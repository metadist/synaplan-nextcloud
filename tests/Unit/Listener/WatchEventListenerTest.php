<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Listener;

use OCA\SynaplanIntegration\Listener\WatchEventListener;
use OCA\SynaplanIntegration\Service\OwnWrites;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\WatchIndex;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class WatchEventListenerTest extends TestCase
{
    private const NOW = 1700000000;
    private const FOLDER_WATCH = ['id' => 5, 'uid' => 'ada', 'kind' => 'folder', 'target' => 30, 'state' => 'active', 'reason' => '', 'since' => 0, 'reconciled' => 0];

    private WatchIndex&MockObject $index;
    private OwnWrites&Stub $ownWrites;
    private bool $registered = true;
    private string $muted = '0';

    protected function setUp(): void
    {
        $this->index = $this->createMock(WatchIndex::class);
        $this->ownWrites = $this->createStub(OwnWrites::class);
    }

    public function testAFileWrittenIntoAWatchedFolderIsQueuedAsChangedNow(): void
    {
        $this->index->method('activeOn')->with([7, 30])->willReturn([self::FOLDER_WATCH]);
        $this->index->expects($this->once())->method('enqueue')->with(5, 7, '', self::NOW);

        $this->listener()->handle(new NodeWrittenEvent($this->file(7, 30)));
    }

    public function testAFileWatchTakesNewVersionsOfItsFileOnly(): void
    {
        $fileWatch = ['id' => 6, 'kind' => 'file', 'target' => 7] + self::FOLDER_WATCH;
        $this->index->method('activeOn')->willReturn([$fileWatch, ['id' => 9, 'target' => 7] + self::FOLDER_WATCH]);
        $this->index->expects($this->once())->method('enqueue')->with(6, 7, '', self::NOW);

        $this->listener()->handle(new NodeWrittenEvent($this->file(7, 30)));
    }

    public function testAFileMovedIntoAWatchedFolderArrivesThere(): void
    {
        $this->index->method('activeOn')->with([7, 30])->willReturn([self::FOLDER_WATCH]);
        $this->index->method('on')->willReturn([]);
        $this->index->expects($this->once())->method('enqueue')->with(5, 7, '', self::NOW);

        $this->listener()->handle(new NodeRenamedEvent($this->file(7, 12), $this->file(7, 30)));
    }

    public function testTheAppsOwnWriteIsIgnored(): void
    {
        $this->ownWrites->method('isOwnVersion')->willReturnCallback(static fn (int $id, string $version): bool => $id === 7 && $version === 'e7');
        $this->index->expects($this->never())->method('activeOn');
        $this->index->expects($this->never())->method('enqueue');

        $this->listener()->handle(new NodeWrittenEvent($this->file(7, 30)));
    }

    public function testAPersonsEditOfAFileThisAppWroteIsQueued(): void
    {
        $this->ownWrites->method('isOwnVersion')->willReturnCallback(static fn (int $id, string $version): bool => $version === 'e6');
        $this->index->method('activeOn')->with([7, 30])->willReturn([self::FOLDER_WATCH]);
        $this->index->expects($this->once())->method('enqueue')->with(5, 7, '', self::NOW);

        $this->listener()->handle(new NodeWrittenEvent($this->file(7, 30)));
    }

    public function testADeletedWatchedFolderIsReportedAtOnce(): void
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getId')->willReturn(30);
        $this->index->method('on')->with([30])->willReturn([self::FOLDER_WATCH, ['id' => 8, 'state' => 'paused'] + self::FOLDER_WATCH]);
        $this->index->expects($this->once())->method('enqueue')->with(5, 30, 'deleted', self::NOW);

        $this->listener()->handle(new NodeDeletedEvent($folder));
    }

    public function testARestoredFolderIsReportedOnlyToAWatchThatLostIt(): void
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getId')->willReturn(30);
        $this->index->method('on')->with([30])->willReturn([
            self::FOLDER_WATCH,
            ['id' => 7, 'state' => 'paused', 'reason' => 'by_owner'] + self::FOLDER_WATCH,
            ['id' => 8, 'state' => 'attention', 'reason' => 'target_gone'] + self::FOLDER_WATCH,
            ['id' => 9, 'state' => 'attention', 'reason' => 'no_access'] + self::FOLDER_WATCH,
        ]);
        $this->index->expects($this->once())->method('enqueue')->with(8, 30, 'restored', self::NOW);

        $this->listener()->handle(new NodeRenamedEvent($folder, $folder));
    }

    public function testAFolderRestoredBeforeItsDeletionWentOutTakesTheDeletionBack(): void
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getId')->willReturn(30);
        $this->index->method('on')->with([30])->willReturn([self::FOLDER_WATCH]);
        $this->index->expects($this->never())->method('enqueue');
        $this->index->expects($this->once())->method('cancelEvent')->with(5, 30, 'deleted');

        $this->listener()->handle(new NodeRenamedEvent($folder, $folder));
    }

    public function testNothingIsQueuedWhileMutedOrUnregistered(): void
    {
        $this->index->expects($this->never())->method('activeOn');
        $this->muted = '1';
        $this->listener()->handle(new NodeWrittenEvent($this->file(7, 30)));
        $this->muted = '0';
        $this->registered = false;
        $this->listener()->handle(new NodeWrittenEvent($this->file(7, 30)));
    }

    public function testAFailingIndexNeverFailsTheWrite(): void
    {
        $this->index->method('activeOn')->willThrowException(new \RuntimeException('no table'));

        $this->listener()->handle(new NodeWrittenEvent($this->file(7, 30)));
        $this->addToAssertionCount(1);
    }

    private function file(int $id, int $parentId): File
    {
        $parent = $this->createStub(Folder::class);
        $parent->method('getId')->willReturn($parentId);
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getEtag')->willReturn('e' . $id);
        $file->method('getParent')->willReturn($parent);

        return $file;
    }

    private function listener(): WatchEventListener
    {
        $synaplan = $this->createStub(SynaplanConfig::class);
        $synaplan->method('isRegistered')->willReturn($this->registered);
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $key === 'dev_mute_events' ? $this->muted : $default,
        );
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        return new WatchEventListener($synaplan, $config, $this->index, $this->ownWrites, $time);
    }
}
