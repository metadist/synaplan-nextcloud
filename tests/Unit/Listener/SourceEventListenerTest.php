<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Listener;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\BackgroundJob\SourceEventsJob;
use OCA\SynaplanIntegration\Listener\SourceEventListener;
use OCA\SynaplanIntegration\Service\ShareRecipients;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Tests\Unit\Support\ArraySourceEventQueue;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\Events\ShareDeletedFromSelfEvent;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SourceEventListenerTest extends TestCase
{
    private SynaplanConfig&MockObject $synaplanConfig;
    private IConfig&MockObject $config;
    private IJobList&MockObject $jobs;
    private IRootFolder&MockObject $rootFolder;
    private ArraySourceEventQueue $queue;
    /** @var array<int, list<string>> who else holds each file id, with a credential */
    private array $sharedWith = [];
    /** @var array<int, list<string>> who has no way left to each file id */
    private array $lostBy = [];
    private string $signedIn = '';
    /** @var array<string, list<string>> the file cache paths below a share each person still has mounted */
    private array $kept = [];
    /** @var list<array{string, mixed}> */
    private array $jobsAdded = [];

    /** @var array<string, string> */
    private array $appConfig = [];
    private bool $registered = true;

    protected function setUp(): void
    {
        $this->synaplanConfig = $this->createMock(SynaplanConfig::class);
        $this->config = $this->createMock(IConfig::class);
        $this->jobs = $this->createMock(IJobList::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->queue = new ArraySourceEventQueue();
        $this->jobs->method('add')->willReturnCallback(function (string $job, mixed $argument): void {
            $this->jobsAdded[] = [$job, $argument];
        });

        $this->synaplanConfig->method('isRegistered')->willReturnCallback(fn (): bool => $this->registered);
        $this->config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $this->appConfig[$key] ?? $default
        );
    }

    private function listener(): SourceEventListener
    {
        $recipients = $this->createStub(ShareRecipients::class);
        $recipients->method('of')->willReturnCallback(fn (Node $node): array => $this->sharedWith[(int) $node->getId()] ?? []);
        $recipients->method('lost')->willReturnCallback(fn (Node $node, string $uid): bool => in_array($uid, $this->lostBy[(int) $node->getId()] ?? [], true));
        $recipients->method('keptBelow')->willReturnCallback(fn (string $uid): array => $this->kept[$uid] ?? []);
        $session = $this->createStub(IUserSession::class);
        if ($this->signedIn !== '') {
            $user = $this->createStub(IUser::class);
            $user->method('getUID')->willReturn($this->signedIn);
            $session->method('getUser')->willReturn($user);
        }

        return new SourceEventListener($this->synaplanConfig, $this->config, $this->jobs, $this->rootFolder, $this->queue, $recipients, $session);
    }

    private function fileNode(int $id, string $path, string $etag = 'etag-1', ?string $owner = null): Node&MockObject
    {
        $node = $this->createMock(Node::class);
        if ($owner !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($owner);
            $node->method('getOwner')->willReturn($user);
        }
        $node->method('getType')->willReturn(FileInfo::TYPE_FILE);
        $node->method('getId')->willReturn($id);
        $node->method('getPath')->willReturn($path);
        $node->method('getEtag')->willReturn($etag);

        return $node;
    }

    /**
     * Ada's folder `Specs` on storage 3, with two files below it in the file
     * cache, as seen at `$path`. Listing it fails the test.
     */
    private function specsFolder(string $path = '/ada/files/Specs', string $internalPath = 'files/Specs', string $owner = ''): Folder&MockObject
    {
        $this->queue->cache(3, 'files/Specs', 50, 'ef');
        $this->queue->cache(3, 'files/Specs/a.pdf', 51, 'ea');
        $this->queue->cache(3, 'files/Specs/Old/b.pdf', 52, 'eb');
        $this->queue->cache(3, 'files/Specsheet.pdf', 60);
        $folder = $this->createMock(Folder::class);
        $folder->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
        $folder->method('getId')->willReturn(50);
        $folder->method('getPath')->willReturn($path);
        $folder->method('getInternalPath')->willReturn($internalPath);
        $folder->method('getEtag')->willReturn('ef');
        if ($owner !== '') {
            $user = $this->createStub(IUser::class);
            $user->method('getUID')->willReturn($owner);
            $folder->method('getOwner')->willReturn($user);
        }
        $folder->expects($this->never())->method('getDirectoryListing');

        return $folder;
    }

    public function testADeletedFolderQueuesItsFilesFromTheFileCacheOnceTheDeletionHappened(): void
    {
        $folder = $this->specsFolder();
        $this->listener()->handle(new BeforeNodeDeletedEvent($folder));

        self::assertSame(['held', 'held'], array_values(array_map(static fn (array $row): string => $row['step'], $this->queue->rows)));
        self::assertSame([], $this->jobsAdded);

        $this->listener()->handle(new NodeDeletedEvent($folder));

        self::assertSame([
            ['uid' => 'ada', 'id' => '51', 'event' => 'deleted', 'version' => 'ea', 'path' => ''],
            ['uid' => 'ada', 'id' => '52', 'event' => 'deleted', 'version' => 'eb', 'path' => ''],
            ['uid' => 'ada', 'id' => '50', 'event' => 'deleted', 'version' => 'ef', 'path' => 'Specs'],
        ], $this->queue->events());
        self::assertSame(['send'], array_values(array_unique(array_map(static fn (array $row): string => $row['step'], $this->queue->rows))));
        self::assertSame(1, $this->queue->cacheReads);
    }

    /**
     * Ada's `Specs/Old` (id 53), as Bob sees it through the share of `Specs`:
     * its internal path is below the share, the file cache keeps Ada's.
     */
    private function oldFolderSeenByBob(): Folder&MockObject
    {
        $this->specsFolder();
        $this->queue->cache(3, 'files/Specs/Old', 53, 'eo');
        $folder = $this->createMock(Folder::class);
        $folder->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
        $folder->method('getId')->willReturn(53);
        $folder->method('getPath')->willReturn('/bob/files/Specs/Old');
        $folder->method('getInternalPath')->willReturn('Old');
        $folder->method('getEtag')->willReturn('eo');
        $ada = $this->createStub(IUser::class);
        $ada->method('getUID')->willReturn('ada');
        $folder->method('getOwner')->willReturn($ada);
        $folder->expects($this->never())->method('getDirectoryListing');

        return $folder;
    }

    public function testASharedFolderTheRecipientDeletesQueuesItsFilesAndNoOthers(): void
    {
        $folder = $this->specsFolder('/bob/files/Specs', '');
        $this->listener()->handle(new BeforeNodeDeletedEvent($folder));
        $this->listener()->handle(new NodeDeletedEvent($folder));

        self::assertSame(['bob send 51', 'bob send 52', 'bob send 50'], $this->queue->steps());
    }

    public function testAFolderInsideAShareTheRecipientDeletesQueuesItsFiles(): void
    {
        $folder = $this->oldFolderSeenByBob();
        $this->listener()->handle(new BeforeNodeDeletedEvent($folder));
        $this->listener()->handle(new NodeDeletedEvent($folder));

        self::assertSame(['bob send 52', 'ada send 52', 'bob send 53', 'ada send 53'], $this->queue->steps());
    }

    public function testASharedFolderTheRecipientRenamesIsWalkedFromItsOwnPath(): void
    {
        $source = $this->createStub(Node::class);
        $source->method('getPath')->willReturn('/bob/files/Specs');
        $this->listener()->handle(new NodeRenamedEvent($source, $this->specsFolder('/bob/files/Specs 2026', '', 'ada')));

        self::assertSame(['bob send 50', 'bob walk 50'], $this->queue->steps(), 'the folder keeps its path for Ada and everyone else');
        self::assertSame('Specs 2026', array_values($this->queue->rows)[1]['path']);
        self::assertSame(0, $this->queue->cacheReads);
    }

    public function testAFolderInsideAShareTheRecipientMovesIsWalkedFromItsOwnPath(): void
    {
        $source = $this->createStub(Node::class);
        $source->method('getPath')->willReturn('/bob/files/Drafts/Old');
        $this->listener()->handle(new NodeRenamedEvent($source, $this->oldFolderSeenByBob()));

        self::assertSame(['bob share 53', 'bob send 53', 'bob walk 53', 'ada send 53', 'ada walk 53'], $this->queue->steps());
        self::assertSame('Specs/Old', array_values($this->queue->rows)[2]['path']);
    }

    public function testAMovedFolderLeavesItsFilesToTheJob(): void
    {
        $source = $this->createStub(Node::class);
        $source->method('getPath')->willReturn('/ada/files/Drafts');
        $this->listener()->handle(new NodeRenamedEvent($source, $this->specsFolder()));

        self::assertSame(['ada share 50', 'ada send 50', 'ada walk 50'], $this->queue->steps());
        self::assertSame('Specs', array_values($this->queue->rows)[2]['path']);
        self::assertSame(0, $this->queue->cacheReads);
    }

    public function testARestoredFolderQueuesItsFilesFromTheFileCache(): void
    {
        $source = $this->createStub(Node::class);
        $source->method('getPath')->willReturn('/ada/files_trashbin/files/Specs.d123');
        $this->listener()->handle(new NodeRenamedEvent($source, $this->specsFolder()));

        self::assertSame(['50 restored', '51 restored', '52 restored'], array_map(
            static fn (array $event): string => $event['id'] . ' ' . $event['event'],
            $this->queue->events(),
        ));
        self::assertSame([[SourceEventsJob::class, ['uid' => 'ada']]], $this->jobsAdded);
    }

    public function testAChangeLeavesThePeopleTheFileIsSharedWithToTheJob(): void
    {
        $this->listener()->handle(new NodeWrittenEvent($this->fileNode(42, '/ada/files/Specs/spec.pdf', 'etag-2')));

        self::assertSame(['ada share 42', 'ada send 42'], $this->queue->steps());
    }

    public function testADeletedFileIsReportedForThePeopleItWasSharedWithToo(): void
    {
        $this->sharedWith[42] = ['bob'];
        $file = $this->fileNode(42, '/ada/files/Specs/spec.pdf', 'etag-2', 'ada');
        $this->listener()->handle(new BeforeNodeDeletedEvent($file));

        self::assertSame(['bob held 42'], $this->queue->steps());

        $this->listener()->handle(new NodeDeletedEvent($file));

        self::assertSame([
            ['uid' => 'bob', 'id' => '42', 'event' => 'deleted', 'version' => 'etag-2', 'path' => ''],
            ['uid' => 'ada', 'id' => '42', 'event' => 'deleted', 'version' => 'etag-2', 'path' => 'Specs/spec.pdf'],
        ], $this->queue->events());
        self::assertSame([[SourceEventsJob::class, ['uid' => 'ada']], [SourceEventsJob::class, ['uid' => 'bob']]], $this->jobsAdded);
    }

    public function testADeletedSharedFolderTakesItsFilesFromThePeopleItWasSharedWith(): void
    {
        $this->sharedWith[50] = ['bob'];
        $folder = $this->specsFolder();
        $this->listener()->handle(new BeforeNodeDeletedEvent($folder));
        $this->listener()->handle(new NodeDeletedEvent($folder));

        self::assertSame(['ada send 51', 'ada send 52', 'bob send 51', 'bob send 52', 'bob send 50', 'ada send 50'], $this->queue->steps());
    }

    public function testWrittenFileQueuesChangedEvent(): void
    {
        $this->listener()->handle(new NodeWrittenEvent($this->fileNode(42, '/ada/files/Specs/spec.pdf', 'etag-2')));

        self::assertSame([['uid' => 'ada', 'id' => '42', 'event' => 'changed', 'version' => 'etag-2', 'path' => 'Specs/spec.pdf']], $this->queue->events());
        self::assertSame([[SourceEventsJob::class, ['uid' => 'ada']]], $this->jobsAdded);
    }

    public function testAPathLongerThanSynaplanTakesIsSentEmpty(): void
    {
        $folder = str_repeat('ä', 130);
        $this->listener()->handle(new NodeWrittenEvent($this->fileNode(42, '/ada/files/' . $folder . '/spec.pdf', 'etag-2')));

        self::assertSame([['uid' => 'ada', 'id' => '42', 'event' => 'changed', 'version' => 'etag-2', 'path' => '']], $this->queue->events());
        self::assertSame([[SourceEventsJob::class, ['uid' => 'ada']]], $this->jobsAdded);
    }

    public function testASharedFileIsReportedForTheEditorAndTheOwnerEachWithTheirPath(): void
    {
        $adaFolder = $this->createMock(Folder::class);
        $adaFolder->method('getFirstNodeById')->with(42)->willReturn($this->fileNode(42, '/ada/files/Specs/spec.pdf'));
        $this->rootFolder->method('getUserFolder')->with('ada')->willReturn($adaFolder);

        $this->listener()->handle(new NodeRenamedEvent(
            $this->fileNode(42, '/bob/files/Shared/old.pdf'),
            $this->fileNode(42, '/bob/files/Shared/spec.pdf', 'etag-2', 'ada'),
        ));

        self::assertSame([
            ['uid' => 'bob', 'id' => '42', 'event' => 'moved', 'version' => 'etag-2', 'path' => 'Shared/spec.pdf'],
            ['uid' => 'ada', 'id' => '42', 'event' => 'moved', 'version' => 'etag-2', 'path' => 'Specs/spec.pdf'],
        ], $this->queue->events());
        self::assertSame([[SourceEventsJob::class, ['uid' => 'bob']], [SourceEventsJob::class, ['uid' => 'ada']]], $this->jobsAdded);
    }

    public function testTheOwnFileOfTheEditorIsReportedOnce(): void
    {
        $this->listener()->handle(new NodeWrittenEvent($this->fileNode(42, '/ada/files/Specs/spec.pdf', 'etag-2', 'ada')));

        self::assertCount(1, $this->queue->events());
        self::assertCount(1, $this->jobsAdded);
    }

    public function testRenamedFileFromTrashQueuesRestoredEvent(): void
    {
        $source = $this->fileNode(42, '/ada/files_trashbin/files/spec.pdf.d123');
        $target = $this->fileNode(42, '/ada/files/Specs/spec.pdf', 'etag-2');
        $this->listener()->handle(new NodeRenamedEvent($source, $target));

        self::assertSame([['uid' => 'ada', 'id' => '42', 'event' => 'restored', 'version' => 'etag-2', 'path' => 'Specs/spec.pdf']], $this->queue->events());
        self::assertSame([[SourceEventsJob::class, ['uid' => 'ada']]], $this->jobsAdded);
    }

    public function testDeletedFileInTrashIsIgnored(): void
    {

        $this->listener()->handle(new NodeDeletedEvent($this->fileNode(42, '/ada/files_trashbin/files/spec.pdf.d123')));

        self::assertSame([], $this->queue->rows);
        self::assertSame([], $this->jobsAdded);
    }

    public function testDevMuteEventsSkipsQueuing(): void
    {
        $this->appConfig[SourceEventListener::DEV_MUTE] = '1';

        $this->listener()->handle(new NodeWrittenEvent($this->fileNode(42, '/ada/files/Specs/spec.pdf')));

        self::assertSame([], $this->queue->rows);
        self::assertSame([], $this->jobsAdded);
    }

    public function testAnUnregisteredNextcloudQueuesNothing(): void
    {
        $this->registered = false;

        $this->listener()->handle(new NodeWrittenEvent($this->fileNode(42, '/ada/files/Specs/spec.pdf')));

        self::assertSame([], $this->queue->rows);
        self::assertSame([], $this->jobsAdded);
    }

    private function share(int $type, string $with, Node $node): IShare
    {
        $share = $this->createStub(IShare::class);
        $share->method('getShareType')->willReturn($type);
        $share->method('getSharedWith')->willReturn($with);
        $share->method('getNode')->willReturn($node);

        return $share;
    }

    public function testARecipientWhoRemovesASharedFolderHasTheirCopiesOfItsFilesDeleted(): void
    {
        $this->lostBy[50] = ['bob'];

        $this->listener()->handle(new ShareDeletedFromSelfEvent($this->share(IShare::TYPE_USER, 'bob', $this->specsFolder())));

        self::assertSame(['bob send 51', 'bob send 52', 'bob send 50'], $this->queue->steps());
        self::assertSame(['deleted'], array_values(array_unique(array_column($this->queue->rows, 'event'))));
        self::assertSame([[SourceEventsJob::class, ['uid' => 'bob']]], $this->jobsAdded);
    }

    public function testWhoLeftAGroupShareIsThePersonSignedIn(): void
    {
        $this->lostBy[42] = ['carol'];
        $this->signedIn = 'carol';

        $this->listener()->handle(new ShareDeletedFromSelfEvent($this->share(IShare::TYPE_GROUP, 'staff', $this->fileNode(42, '/ada/files/a.pdf', 'e42'))));

        self::assertSame(['carol send 42'], $this->queue->steps());
        self::assertSame([['uid' => 'carol', 'id' => '42', 'event' => 'deleted', 'version' => 'e42', 'path' => '']], $this->queue->events());
    }

    public function testARecipientWhoStillReachesTheFilesKeepsTheirCopies(): void
    {
        $this->lostBy[50] = [];

        $this->listener()->handle(new ShareDeletedFromSelfEvent($this->share(IShare::TYPE_USER, 'bob', $this->specsFolder())));

        self::assertSame([], $this->queue->steps());
        self::assertSame([], $this->jobsAdded);
    }

    public function testARecipientKeepsTheirCopiesOfAFolderTheyStillHaveASharedOfTheirOwn(): void
    {
        $this->lostBy[50] = ['bob'];
        $this->kept['bob'] = ['files/Specs/Old'];
        $folder = $this->specsFolder();
        $this->queue->cache(3, 'files/Specs/Old', 53, 'eo');

        $this->listener()->handle(new ShareDeletedFromSelfEvent($this->share(IShare::TYPE_USER, 'bob', $folder)));

        self::assertSame(['bob send 51', 'bob send 50'], $this->queue->steps(), 'Old and b.pdf in it stay Bob\'s through his share of Old');
    }
}
