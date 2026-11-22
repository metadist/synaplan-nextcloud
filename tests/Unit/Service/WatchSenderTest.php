<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\OwnWrites;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\WatchSender;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WatchSenderTest extends TestCase
{
    private const WATCH = ['id' => 5, 'uid' => 'ada', 'kind' => 'folder', 'target' => 30, 'state' => 'active', 'since' => 0, 'reconciled' => 0];

    private PlatformLinkService&MockObject $links;
    private OwnWrites&Stub $ownWrites;
    private Folder&Stub $home;

    protected function setUp(): void
    {
        $this->links = $this->createMock(PlatformLinkService::class);
        $this->ownWrites = $this->createStub(OwnWrites::class);
        $this->home = $this->createStub(Folder::class);
        $this->home->method('getRelativePath')->willReturnCallback(
            static fn (string $path): string => substr($path, strlen('/ada/files')),
        );
    }

    public function testAFileGoesWithItsEtagNamePathAndBytes(): void
    {
        $this->file(7, 30, 'Incoming/report.pdf', 'e1', 1234);
        $this->links->expects($this->once())->method('sendWatchFile')
            ->with('ada', 5, ['id' => '7', 'version' => 'e1', 'name' => 'report.pdf', 'path' => 'Incoming/report.pdf'], $this->callback(
                static fn (?array $file): bool => $file !== null && is_resource($file['bytes'])
                    && $file['name'] === 'report.pdf' && $file['mime'] === 'application/pdf',
            ))
            ->willReturn(['status_code' => 200, 'state' => 'accepted']);

        self::assertSame(WatchSender::SENT, $this->sender()->file(self::WATCH, 7));
    }

    public function testAFileThisAppWroteGoesAsAnOwnWriteWithoutItsBytes(): void
    {
        $this->file(7, 30, 'Incoming/report.en.pdf', 'e1', 1234);
        $this->ownWrites->method('isUnchanged')->willReturn(true);
        $this->links->expects($this->once())->method('sendWatchFile')
            ->with('ada', 5, $this->callback(static fn (array $fields): bool => ($fields['own_write'] ?? '') === '1'), null)
            ->willReturn(['status_code' => 200, 'state' => 'skipped']);

        self::assertSame(WatchSender::SENT, $this->sender()->file(self::WATCH, 7));
    }

    public function testAFileOverOneHundredMebibytesIsNotSent(): void
    {
        $this->file(7, 30, 'Incoming/video.mp4', 'e1', WatchSender::MAX_BYTES + 1);
        $this->links->expects($this->never())->method('sendWatchFile');

        self::assertSame(WatchSender::DROP, $this->sender()->file(self::WATCH, 7));
    }

    public function testAFileThatLeftTheFolderIsNotSent(): void
    {
        $this->file(7, 31, 'Elsewhere/report.pdf', 'e1', 10);
        $this->links->expects($this->never())->method('sendWatchFile');

        self::assertSame(WatchSender::DROP, $this->sender()->file(self::WATCH, 7));
    }

    public function testAFileWatchTakesOnlyItsOwnFile(): void
    {
        $this->file(8, 30, 'Incoming/other.pdf', 'e1', 10);
        $this->links->expects($this->never())->method('sendWatchFile');

        self::assertSame(WatchSender::DROP, $this->sender()->file(['kind' => 'file', 'target' => 7] + self::WATCH, 8));
    }

    public function testATargetEventGoesWithoutAFile(): void
    {
        $this->links->expects($this->once())->method('sendWatchFile')
            ->with('ada', 5, ['id' => '30', 'version' => 'deleted-1700000000', 'event' => 'deleted'], null)
            ->willReturn(['status_code' => 200, 'state' => 'attention']);

        self::assertSame(WatchSender::SENT, $this->sender()->targetEvent(self::WATCH, 'deleted', 1700000000));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function answers(): iterable
    {
        yield 'a watch no longer running' => [['status_code' => 409, 'error' => 'invalid_watch'], WatchSender::NOT_RUNNING];
        yield 'an unknown watch' => [['status_code' => 404], WatchSender::NOT_RUNNING];
        yield 'a full storage' => [['status_code' => 409, 'error' => 'storage_quota'], WatchSender::RETRY];
        yield 'a busy Synaplan' => [['status_code' => 503], WatchSender::RETRY];
        yield 'an expired credential' => [['status_code' => 401], WatchSender::RETRY];
        yield 'a refused file' => [['status_code' => 422, 'message' => 'This watch follows one file.'], WatchSender::DROP];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('answers')]
    public function testSynaplansAnswerDecidesWhatHappensNext(array $answer, string $expected): void
    {
        $this->file(7, 30, 'Incoming/report.pdf', 'e1', 10);
        $this->links->method('sendWatchFile')->willReturn($answer);

        self::assertSame($expected, $this->sender()->file(self::WATCH, 7));
    }

    public function testAnUnreachableSynaplanIsRetried(): void
    {
        $this->file(7, 30, 'Incoming/report.pdf', 'e1', 10);
        $this->links->method('sendWatchFile')->willThrowException(new \RuntimeException('cURL error 7'));

        self::assertSame(WatchSender::RETRY, $this->sender()->file(self::WATCH, 7));
    }

    private function file(int $id, int $parentId, string $relative, string $etag, int $size): void
    {
        $parent = $this->createStub(Folder::class);
        $parent->method('getId')->willReturn($parentId);
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getParent')->willReturn($parent);
        $file->method('getName')->willReturn(basename($relative));
        $file->method('getPath')->willReturn('/ada/files/' . $relative);
        $file->method('getEtag')->willReturn($etag);
        $file->method('getSize')->willReturn($size);
        $file->method('getMTime')->willReturn(1700000000);
        $file->method('getMimetype')->willReturn(str_ends_with($relative, '.pdf') ? 'application/pdf' : 'video/mp4');
        $file->method('isReadable')->willReturn(true);
        $file->method('fopen')->willReturnCallback(static fn () => fopen('php://memory', 'r'));
        $this->home->method('getFirstNodeById')->willReturnCallback(
            static fn (int $wanted): ?File => $wanted === $id ? $file : null,
        );
    }

    private function sender(): WatchSender
    {
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($this->home);

        return new WatchSender($this->links, $root, $this->ownWrites, new NullLogger());
    }
}
