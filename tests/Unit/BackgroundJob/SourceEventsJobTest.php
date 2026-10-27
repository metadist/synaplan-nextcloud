<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\BackgroundJob;

use OCA\SynaplanIntegration\BackgroundJob\ReuploadJob;
use OCA\SynaplanIntegration\BackgroundJob\SourceEventsJob;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SourceEventsJobTest extends TestCase
{
    private PlatformLinkService&MockObject $links;
    private IJobList&MockObject $jobs;

    protected function setUp(): void
    {
        $this->links = $this->createMock(PlatformLinkService::class);
        $this->jobs = $this->createMock(IJobList::class);
    }

    public function testForwardsEventAndQueuesReuploads(): void
    {
        $this->links->expects($this->once())
            ->method('reportSourceEvents')
            ->with([['id' => '42', 'event' => 'changed', 'version' => 'e2', 'path' => 'Specs/spec.pdf']])
            ->willReturn([
                'applied' => 1,
                'ignored' => 0,
                'reupload' => [['external_id' => 'ada', 'id' => '42']],
            ]);
        $this->jobs->expects($this->once())
            ->method('add')
            ->with(ReuploadJob::class, ['uid' => 'ada', 'id' => '42']);

        $job = new SourceEventsJob($this->createStub(ITimeFactory::class), $this->links, $this->jobs);
        $method = new \ReflectionMethod(SourceEventsJob::class, 'run');
        $method->invoke($job, ['id' => '42', 'event' => 'changed', 'version' => 'e2', 'path' => 'Specs/spec.pdf']);
    }
}
