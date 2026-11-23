<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Listener;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\BackgroundJob\SourceEventsJob;
use OCA\SynaplanIntegration\Listener\SourceEventListener;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Tests\Unit\Support\ArraySourceEventQueue;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SourceEventListenerTest extends TestCase
{
    private SynaplanConfig&MockObject $synaplanConfig;
    private IConfig&MockObject $config;
    private IJobList&MockObject $jobs;
    private IRootFolder&MockObject $rootFolder;
    private ArraySourceEventQueue $queue;
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
        return new SourceEventListener($this->synaplanConfig, $this->config, $this->jobs, $this->rootFolder, $this->queue);
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

        self::assertCount(1, $this->queue->rows);
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
}
