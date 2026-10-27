<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Listener;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\BackgroundJob\SourceEventsJob;
use OCA\SynaplanIntegration\Listener\SourceEventListener;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SourceEventListenerTest extends TestCase
{
    private SynaplanConfig&MockObject $synaplanConfig;
    private IConfig&MockObject $config;
    private IJobList&MockObject $jobs;

    /** @var array<string, string> */
    private array $appConfig = [];

    protected function setUp(): void
    {
        $this->synaplanConfig = $this->createMock(SynaplanConfig::class);
        $this->config = $this->createMock(IConfig::class);
        $this->jobs = $this->createMock(IJobList::class);

        $this->synaplanConfig->method('getLinkInstanceId')->willReturn('pi_nc');
        $this->synaplanConfig->method('getLinkInstanceSecretCipher')->willReturn('enc:secret');
        $this->config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $this->appConfig[$key] ?? $default
        );
    }

    private function listener(): SourceEventListener
    {
        return new SourceEventListener($this->synaplanConfig, $this->config, $this->jobs);
    }

    private function fileNode(int $id, string $path, string $etag = 'etag-1'): Node&MockObject
    {
        $node = $this->createMock(Node::class);
        $node->method('getType')->willReturn(FileInfo::TYPE_FILE);
        $node->method('getId')->willReturn($id);
        $node->method('getPath')->willReturn($path);
        $node->method('getEtag')->willReturn($etag);

        return $node;
    }

    public function testWrittenFileQueuesChangedEvent(): void
    {
        $this->jobs->expects($this->once())->method('add')->with(
            SourceEventsJob::class,
            ['id' => '42', 'event' => 'changed', 'version' => 'etag-2', 'path' => 'Specs/spec.pdf'],
        );

        $this->listener()->handle(new NodeWrittenEvent($this->fileNode(42, '/ada/files/Specs/spec.pdf', 'etag-2')));
    }

    public function testRenamedFileFromTrashQueuesRestoredEvent(): void
    {
        $this->jobs->expects($this->once())->method('add')->with(
            SourceEventsJob::class,
            ['id' => '42', 'event' => 'restored', 'version' => 'etag-2', 'path' => 'Specs/spec.pdf'],
        );

        $source = $this->fileNode(42, '/ada/files_trashbin/files/spec.pdf.d123');
        $target = $this->fileNode(42, '/ada/files/Specs/spec.pdf', 'etag-2');
        $this->listener()->handle(new NodeRenamedEvent($source, $target));
    }

    public function testDeletedFileInTrashIsIgnored(): void
    {
        $this->jobs->expects($this->never())->method('add');

        $this->listener()->handle(new NodeDeletedEvent($this->fileNode(42, '/ada/files_trashbin/files/spec.pdf.d123')));
    }

    public function testDevMuteEventsSkipsQueuing(): void
    {
        $this->appConfig[SourceEventListener::DEV_MUTE] = '1';
        $this->jobs->expects($this->never())->method('add');

        $this->listener()->handle(new NodeWrittenEvent($this->fileNode(42, '/ada/files/Specs/spec.pdf')));
    }
}
