<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\WatchFeed;
use OCA\SynaplanIntegration\Service\WatchIndex;
use OCA\SynaplanIntegration\Service\WatchSender;
use OCA\SynaplanIntegration\Service\WatchSync;
use OCP\Files\Folder;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class WatchFeedTest extends TestCase
{
    private const NOW = 1700000000;
    private const WATCH = ['id' => 5, 'uid' => 'ada', 'kind' => 'folder', 'target' => 30, 'state' => 'active', 'reason' => '', 'since' => 0, 'reconciled' => 0];

    private WatchIndex&MockObject $index;
    private WatchSender&MockObject $sender;
    private WatchSync&MockObject $sync;

    protected function setUp(): void
    {
        $this->index = $this->createMock(WatchIndex::class);
        $this->sender = $this->createMock(WatchSender::class);
        $this->sync = $this->createMock(WatchSync::class);
    }

    public function testAQuietFileIsSentAndLeavesTheQueue(): void
    {
        $this->queue(['watch' => 5, 'file' => 7, 'event' => '', 'changed' => self::NOW - 90, 'generation' => 'g1', 'attempts' => 0], self::WATCH);
        $this->sender->expects($this->once())->method('file')->with(self::WATCH, 7)->willReturn(WatchSender::SENT);
        $this->index->expects($this->once())->method('done')->with(self::row(7));

        $this->feed()->run(self::NOW, microtime(true) + 60);
    }

    public function testAFileForAPausedWatchWaitsForTheNextPassInstead(): void
    {
        $this->queue(['watch' => 5, 'file' => 7, 'event' => '', 'changed' => self::NOW - 90, 'generation' => 'g1', 'attempts' => 0], ['state' => 'paused'] + self::WATCH);
        $this->sender->expects($this->never())->method('file');
        $this->index->expects($this->once())->method('done')->with(self::row(7));

        $this->feed()->run(self::NOW, microtime(true) + 60);
    }

    public function testAFailedSendIsRetriedLaterWithABackoffAndThenGivenUp(): void
    {
        $this->queue(['watch' => 5, 'file' => 7, 'event' => '', 'changed' => self::NOW - 90, 'generation' => 'g1', 'attempts' => 2], self::WATCH);
        $this->sender->method('file')->willReturn(WatchSender::RETRY);
        $this->index->expects($this->once())->method('retryLater')->with(self::row(7, 2), self::NOW + 240, 3);
        $this->index->expects($this->never())->method('done');

        $this->feed()->run(self::NOW, microtime(true) + 60);

        $this->setUp();
        $this->queue(['watch' => 5, 'file' => 7, 'event' => '', 'changed' => self::NOW - 90, 'generation' => 'g1', 'attempts' => 4], self::WATCH);
        $this->sender->method('file')->willReturn(WatchSender::RETRY);
        $this->index->expects($this->never())->method('retryLater');
        $this->index->expects($this->once())->method('done')->with(self::row(7, 4));

        $this->feed()->run(self::NOW, microtime(true) + 60);
    }

    public function testAWatchSynaplanNoLongerRunsSyncsItsOwner(): void
    {
        $this->queue(['watch' => 5, 'file' => 7, 'event' => '', 'changed' => self::NOW - 90, 'generation' => 'g1', 'attempts' => 0], self::WATCH);
        $this->sender->method('file')->willReturn(WatchSender::NOT_RUNNING);
        $this->index->expects($this->once())->method('done')->with(self::row(7));
        $this->sync->expects($this->once())->method('request')->with('ada');

        $this->feed()->run(self::NOW, microtime(true) + 60);
    }

    public function testARestoredTargetIsReportedWhileItsWatchSaysItIsGone(): void
    {
        $watch = ['state' => 'attention'] + self::WATCH;
        $this->queue(['watch' => 5, 'file' => 30, 'event' => 'restored', 'changed' => self::NOW, 'generation' => 'g1', 'attempts' => 0], $watch);
        $this->sender->expects($this->once())->method('targetEvent')->with($watch, 'restored', self::NOW)->willReturn(WatchSender::SENT);
        $this->sync->expects($this->once())->method('request')->with('ada');

        $this->feed()->run(self::NOW, microtime(true) + 60);
    }

    public function testATargetRestoredWhileItsDeletionWasOnItsWayIsReportedRestored(): void
    {
        $this->queue(['watch' => 5, 'file' => 30, 'event' => 'deleted', 'changed' => self::NOW, 'generation' => 'g1', 'attempts' => 0], self::WATCH);
        $this->sender->method('ownerNode')->with('ada', 30)->willReturn($this->createStub(Folder::class));
        $events = [];
        $this->sender->method('targetEvent')->willReturnCallback(function (array $watch, string $event) use (&$events): string {
            $events[] = $event;

            return WatchSender::SENT;
        });

        $this->feed()->run(self::NOW, microtime(true) + 60);

        self::assertSame(['deleted', 'restored'], $events);
    }

    public function testARestoreThatCouldNotGoIsQueuedWithoutTheDeletion(): void
    {
        $row = ['watch' => 5, 'file' => 30, 'event' => 'deleted', 'changed' => self::NOW, 'generation' => 'g1', 'attempts' => 0];
        $this->queue($row, self::WATCH);
        $this->sender->method('ownerNode')->willReturn($this->createStub(Folder::class));
        $this->sender->method('targetEvent')->willReturnCallback(
            static fn (array $watch, string $event): string => $event === 'deleted' ? WatchSender::SENT : WatchSender::RETRY,
        );
        $calls = [];
        $this->index->method('done')->willReturnCallback(function (array $settled) use (&$calls): void {
            $calls[] = 'done ' . $settled['event'];
        });
        $this->index->method('enqueueIfAbsent')->willReturnCallback(function (int $watch, int $file, string $event, int $changed, int $due) use (&$calls): bool {
            $calls[] = "queue {$watch} {$file} {$event} {$changed} {$due}";

            return true;
        });
        $this->index->expects($this->never())->method('retryLater');

        $this->feed()->run(self::NOW, microtime(true) + 60);

        self::assertSame(['done deleted', 'queue 5 30 restored ' . self::NOW . ' ' . (self::NOW + 60)], $calls);
    }

    public function testARestoreThatWentQueuesNothing(): void
    {
        $this->queue(['watch' => 5, 'file' => 30, 'event' => 'deleted', 'changed' => self::NOW, 'generation' => 'g1', 'attempts' => 0], self::WATCH);
        $this->sender->method('ownerNode')->willReturn($this->createStub(Folder::class));
        $this->sender->method('targetEvent')->willReturn(WatchSender::SENT);
        $this->index->expects($this->never())->method('enqueueIfAbsent');

        $this->feed()->run(self::NOW, microtime(true) + 60);
    }

    public function testADeletedTargetThatStaysGoneIsOnlyReportedDeleted(): void
    {
        $this->queue(['watch' => 5, 'file' => 30, 'event' => 'deleted', 'changed' => self::NOW, 'generation' => 'g1', 'attempts' => 0], self::WATCH);
        $this->sender->method('ownerNode')->willReturn(null);
        $this->sender->expects($this->once())->method('targetEvent')->with(self::WATCH, 'deleted', self::NOW)->willReturn(WatchSender::SENT);

        $this->feed()->run(self::NOW, microtime(true) + 60);
    }

    public function testTheQuietPeriodIsSixtySecondsUnlessSetForTheJourneys(): void
    {
        self::assertSame(60, $this->feed()->quietSeconds());
        self::assertSame(1, $this->feed('1')->quietSeconds());
        self::assertSame(3600, $this->feed('99999')->quietSeconds());
    }

    /**
     * @param array{watch: int, file: int, event: string, changed: int, generation: string, attempts: int} $row
     * @param array{id: int, uid: string, kind: string, target: int, state: string, since: int, reconciled: int} $watch
     */
    private function queue(array $row, array $watch): void
    {
        $this->index->method('due')->with(self::NOW, 60, $this->greaterThan(0))->willReturn([$row]);
        $this->index->method('find')->with($row['watch'])->willReturn($watch);
    }

    /**
     * @return array{watch: int, file: int, event: string, changed: int, generation: string, attempts: int}
     */
    private static function row(int $file, int $attempts = 0): array
    {
        return ['watch' => 5, 'file' => $file, 'event' => '', 'changed' => self::NOW - 90, 'generation' => 'g1', 'attempts' => $attempts];
    }

    private function feed(?string $quiet = null): WatchFeed
    {
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = ''): string => $quiet ?? $default,
        );

        return new WatchFeed($this->index, $this->sender, $this->sync, $config);
    }
}
