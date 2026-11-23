<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\BackgroundJob;

use OCA\SynaplanIntegration\BackgroundJob\WatchFeedJob;
use OCA\SynaplanIntegration\BackgroundJob\WatchReconcileJob;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\WatchFeed;
use OCA\SynaplanIntegration\Service\WatchReconcile;
use OCA\SynaplanIntegration\Service\WatchSync;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class WatchJobsTest extends TestCase
{
    private const NOW = 1700000000;

    public function testEveryMinuteTheIndexIsSyncedNewWatchesGetTheirFirstPassAndQuietFilesGo(): void
    {
        $order = [];
        $sync = $this->createMock(WatchSync::class);
        $sync->expects($this->once())->method('run')->with(self::NOW)
            ->willReturnCallback(function () use (&$order): void {
                $order[] = 'sync';
            });
        $reconcile = $this->createMock(WatchReconcile::class);
        $reconcile->expects($this->once())->method('run')->with(self::NOW, $this->isFloat(), true)
            ->willReturnCallback(function () use (&$order): void {
                $order[] = 'first pass';
            });
        $feed = $this->createMock(WatchFeed::class);
        $feed->expects($this->once())->method('run')->with(self::NOW, $this->isFloat())
            ->willReturnCallback(function () use (&$order): void {
                $order[] = 'feed';
            });

        $this->invoke(new WatchFeedJob($this->time(), $this->registered(true), $sync, $reconcile, $feed));

        self::assertSame(['sync', 'first pass', 'feed'], $order);
    }

    public function testTheReconcileJobTakesEveryWatchThatIsDue(): void
    {
        $reconcile = $this->createMock(WatchReconcile::class);
        $reconcile->expects($this->once())->method('run')->with(self::NOW, $this->isFloat(), false);

        $this->invoke(new WatchReconcileJob($this->time(), $this->registered(true), $reconcile));
    }

    public function testNothingRunsBeforeTheRegistration(): void
    {
        $sync = $this->createMock(WatchSync::class);
        $sync->expects($this->never())->method('run');
        $reconcile = $this->createMock(WatchReconcile::class);
        $reconcile->expects($this->never())->method('run');

        $this->invoke(new WatchFeedJob($this->time(), $this->registered(false), $sync, $reconcile, $this->createStub(WatchFeed::class)));
        $this->invoke(new WatchReconcileJob($this->time(), $this->registered(false), $reconcile));
    }

    private function invoke(object $job): void
    {
        (new \ReflectionMethod($job, 'run'))->invoke($job, null);
    }

    private function time(): ITimeFactory
    {
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        return $time;
    }

    private function registered(bool $registered): SynaplanConfig
    {
        $config = $this->createStub(SynaplanConfig::class);
        $config->method('isRegistered')->willReturn($registered);

        return $config;
    }
}
