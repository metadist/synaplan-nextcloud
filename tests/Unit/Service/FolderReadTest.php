<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\FolderRead;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\ReadScope;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FolderReadTest extends TestCase
{
    private PlatformLinkService&MockObject $links;
    private ReadScope&MockObject $scope;
    private IJobList&MockObject $jobs;
    private FolderRead $reads;
    private string $etag = 'etag-1';
    private int $etagReads = 0;

    protected function setUp(): void
    {
        $this->links = $this->createMock(PlatformLinkService::class);
        $this->scope = $this->createMock(ReadScope::class);
        $this->jobs = $this->createMock(IJobList::class);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(
            fn (string $uid): ?IUser => $uid === 'ada' ? $this->createMock(IUser::class) : null,
        );

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(7);
        $file->method('getName')->willReturn('a.txt');
        $file->method('getSize')->willReturn(5);
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getEtag')->willReturnCallback(function (): string {
            $this->etagReads++;

            return $this->etagReads === 1 ? 'etag-1' : $this->etag;
        });
        $file->method('getMTime')->willReturn(time());
        $file->method('getContent')->willReturn('hello');
        $file->method('getPath')->willReturn('/ada/files/Invoices/a.txt');

        $invoices = $this->createMock(Folder::class);
        $invoices->method('getDirectoryListing')->willReturn([$file]);
        $invoices->method('getPath')->willReturn('/ada/files/Invoices');
        $invoices->method('getName')->willReturn('Invoices');

        $home = $this->createMock(Folder::class);
        $home->method('getPath')->willReturn('/ada/files');
        $home->method('get')->with('Invoices')->willReturn($invoices);
        $home->method('getById')->with(7)->willReturn([$file]);
        $home->method('getRelativePath')->willReturnCallback(static fn (string $path): string => match ($path) {
            '/ada/files/Invoices' => '/Invoices',
            '/ada/files/Invoices/a.txt' => '/Invoices/a.txt',
            default => '/',
        });

        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->with('ada')->willReturn($home);

        $this->reads = new FolderRead(
            $this->links,
            $this->scope,
            $root,
            $users,
            $this->jobs,
            new NullLogger(),
        );
    }

    public function testAFolderOutsideTheSettingIsRefusedBeforeAnyListing(): void
    {
        $this->scope->method('mayRead')->with('ada', 'Invoices')->willReturn(false);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->expects($this->never())->method('submitPickupListing');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', $this->callback(
            static fn (array $body): bool => $body['status'] === 'failed' && $body['error_code'] === 'outside_scope',
        ));

        $this->reads->handle('15');
    }

    public function testAnAllowedFolderIsListedAndTheAskedFileIsSent(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->expects($this->once())->method('submitPickupListing')->with('15', $this->callback(
            static fn (array $items): bool => ($items[0]['id'] ?? '') === '7' && ($items[0]['name'] ?? '') === 'a.txt',
        ))->willReturn(['upload' => ['7'], 'reuse' => [], 'skip' => []]);
        $this->links->expects($this->once())->method('uploadPickupFile')->with('15', '7', 'etag-1', 'hello', 'a.txt', 'text/plain', $this->greaterThan(0))
            ->willReturn(['status_code' => 200]);
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', $this->callback(
            static fn (array $body): bool => $body['status'] === 'done' && $body['sent'] === 1 && $body['listed'] === 1,
        ));
        $this->jobs->expects($this->never())->method('add');

        $this->reads->handle('15');
    }

    public function testAFileChangedSinceTheListingIsNotSentUnderItsOldVersion(): void
    {
        $this->etag = 'etag-2';
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturn(['upload' => ['7'], 'reuse' => [], 'skip' => []]);
        $this->links->expects($this->never())->method('uploadPickupFile');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', $this->callback(
            static fn (array $body): bool => $body['status'] === 'partial'
                && ($body['skipped'][0]['reason'] ?? '') === 'changed_since_listing',
        ));

        $this->reads->handle('15');
    }

    public function testASelectorThatEscapesItsFolderIsRefused(): void
    {
        $claim = $this->claim();
        $claim['selector'] = ['folder_path' => 'Invoices/../HR', 'recursive' => false];
        $this->links->method('claimPickup')->willReturn($claim);
        $this->links->expects($this->never())->method('submitPickupListing');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', $this->callback(
            static fn (array $body): bool => $body['status'] === 'failed' && $body['error_code'] === 'not_found',
        ));

        $this->reads->handle('15');
    }

    public function testAQueuedUploadRechecksTheSettingBeforeReading(): void
    {
        $this->scope->method('mayRead')->willReturn(false);
        $this->links->expects($this->never())->method('uploadPickupFile');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', $this->callback(
            static fn (array $body): bool => $body['status'] === 'partial'
                && ($body['skipped'][0]['reason'] ?? '') === 'outside_scope',
        ));

        $this->reads->finish('15', [
            'uid' => 'ada',
            'left' => [['id' => '7', 'version' => 'etag-1']],
            'sent' => 0,
            'listed' => 1,
            'skipped' => [],
        ]);
    }

    public function testAFailedUploadClosesTheReadAsPartial(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturn(['upload' => ['7'], 'reuse' => [], 'skip' => []]);
        $this->links->method('uploadPickupFile')->willThrowException(new \RuntimeException('timed out'));
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', $this->callback(
            static fn (array $body): bool => $body['status'] === 'partial'
                && ($body['skipped'][0]['reason'] ?? '') === 'unreachable',
        ));

        $this->reads->handle('15');
    }

    /**
     * @return array<string, mixed>
     */
    private function claim(): array
    {
        return [
            'external_id' => 'ada',
            'mode' => 'fetch',
            'selector' => ['folder_path' => 'Invoices', 'recursive' => false],
        ];
    }
}
