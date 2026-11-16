<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\BackgroundJob;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\BackgroundJob\AdoptIdentitiesJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class AdoptIdentitiesJobTest extends TestCase
{
    public function testOnePassMarksTheJobFinished(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnOnConsecutiveCalls('0', '1');
        $config->expects($this->once())->method('setAppValue')->with(
            Application::APP_ID,
            AdoptIdentitiesJob::DONE,
            '1',
        );
        $job = new AdoptIdentitiesJob($this->createStub(ITimeFactory::class), $config);

        $run = new \ReflectionMethod(AdoptIdentitiesJob::class, 'run');
        $run->invoke($job, null);
        $run->invoke($job, null);
    }
}
