<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\OwnWrites;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\WatchIndex;
use OCA\SynaplanIntegration\Service\WatchPolicy;
use OCA\SynaplanIntegration\Service\WatchReconcile;
use OCA\SynaplanIntegration\Service\WatchSender;
use OCA\SynaplanIntegration\Service\WatchSync;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WatchReconcileTest extends TestCase
{
    private const NOW = 1700000000;
    private const WATCH = ['id' => 5, 'uid' => 'ada', 'kind' => 'folder', 'target' => 30, 'state' => 'active', 'reason' => '', 'since' => 0, 'reconciled' => 0];

    private WatchIndex&MockObject $index;
    private WatchSender&MockObject $sender;
    private WatchSync&MockObject $sync;
    private PlatformLinkService&MockObject $links;
    private bool $sharedOff = false;

    protected function setUp(): void
    {
        $this->index = $this->createMock(WatchIndex::class);
        $this->sender = $this->createMock(WatchSender::class);
        $this->sync = $this->createMock(WatchSync::class);
        $this->links = $this->createMock(PlatformLinkService::class);
    }

    public function testTheFilesDirectlyInTheFolderAreAskedAboutAndTheMissingOnesSent(): void
    {
        $this->watching(self::WATCH, $this->folder([
            $this->file(7, 'e7', self::NOW - 7200),
            $this->file(8, 'e8', self::NOW - 60),
            $this->createStub(Folder::class),
        ]));
        $this->links->expects($this->once())->method('reconcileWatch')
            ->with('ada', 5, [['id' => '7', 'version' => 'e7'], ['id' => '8', 'version' => 'e8']])
            ->willReturn(['process' => ['8', '99'], 'forget' => ['7']]);
        $this->sender->expects($this->once())->method('file')->with(self::WATCH, 8)->willReturn(WatchSender::SENT);
        $this->index->expects($this->once())->method('markReconciled')->with(5, self::NOW);

        $this->reconcile()->run(self::NOW, microtime(true) + 60, false);
    }

    public function testOnlyFilesChangedSinceTheWatchStartedCountWithoutTheFilesAlreadyThere(): void
    {
        $this->watching(['since' => self::NOW - 600] + self::WATCH, $this->folder([
            $this->file(7, 'e7', self::NOW - 7200),
            $this->file(8, 'e8', self::NOW - 60),
        ]));
        $this->links->expects($this->once())->method('reconcileWatch')
            ->with('ada', 5, [['id' => '8', 'version' => 'e8']])
            ->willReturn(['process' => [], 'forget' => ['8']]);

        $this->reconcile()->run(self::NOW, microtime(true) + 60, false);
    }

    public function testASharedFolderIsNotAskedAboutWhileTheAdministratorTurnedThatOff(): void
    {
        $this->watching(self::WATCH, $this->folder([$this->file(7, 'e7', self::NOW)]));
        $this->sharedOff = true;
        $this->links->expects($this->never())->method('reconcileWatch');
        $this->index->expects($this->once())->method('markReconciled');

        $this->reconcile()->run(self::NOW, microtime(true) + 60, false);
    }

    public function testThePausedWatchCountsTheFilesSynaplanStillLacksAndSendsNothing(): void
    {
        $paused = ['state' => 'paused', 'since' => self::NOW - 600] + self::WATCH;
        $this->watching($paused, $this->folder([
            $this->file(7, 'e7', self::NOW - 7200),
            $this->file(8, 'e8', self::NOW - 60),
            $this->file(9, 'e9', self::NOW - 30),
        ]));
        $this->links->expects($this->once())->method('reconcileWatch')
            ->with('ada', 5, [['id' => '8', 'version' => 'e8'], ['id' => '9', 'version' => 'e9']])
            ->willReturn(['process' => ['9', '99'], 'forget' => ['8']]);
        $this->sender->expects($this->never())->method('file');
        $this->index->expects($this->never())->method('markReconciled');

        self::assertSame(1, $this->reconcile()->waiting($paused));
    }

    public function testAFileMovedInWhilePausedCountsWhateverItsTime(): void
    {
        $paused = ['state' => 'paused', 'since' => self::NOW - 600] + self::WATCH;
        $this->watching($paused, $this->folder([
            $this->file(7, 'e7', self::NOW - 365 * 86400),
            $this->file(8, 'e8', self::NOW - 7200),
        ]));
        $this->index->method('queuedFiles')->with(5)->willReturn([7]);
        $this->links->expects($this->once())->method('reconcileWatch')
            ->with('ada', 5, [['id' => '7', 'version' => 'e7']])
            ->willReturn(['process' => ['7'], 'forget' => []]);

        self::assertSame(1, $this->reconcile()->waiting($paused));
    }

    public function testAHeldTargetHasNothingWaiting(): void
    {
        $this->watching(self::WATCH, $this->folder([$this->file(7, 'e7', self::NOW)]));
        $this->sharedOff = true;
        $this->links->expects($this->never())->method('reconcileWatch');

        self::assertSame(0, $this->reconcile()->waiting(self::WATCH));
    }

    public function testAnEmptyFolderAsksNothingAndIsStillReconciled(): void
    {
        $this->watching(self::WATCH, $this->folder([]));
        $this->links->expects($this->never())->method('reconcileWatch');
        $this->index->expects($this->once())->method('markReconciled');

        $this->reconcile()->run(self::NOW, microtime(true) + 60, false);
    }

    public function testFiveHundredFilesGoPerQuestion(): void
    {
        $files = [];
        for ($id = 1; $id <= 501; $id++) {
            $files[] = $this->file($id, 'e' . $id, self::NOW);
        }
        $this->watching(self::WATCH, $this->folder($files));
        $sizes = [];
        $this->links->expects($this->exactly(2))->method('reconcileWatch')->willReturnCallback(
            function (string $uid, int $watch, array $items) use (&$sizes): array {
                $sizes[] = count($items);

                return ['process' => [], 'forget' => []];
            },
        );

        $this->reconcile()->run(self::NOW, microtime(true) + 60, false);

        self::assertSame([500, 1], $sizes);
    }

    public function testAPassThatRunsOutOfTimeStopsBetweenQuestionsAndIsTriedAgain(): void
    {
        $files = [];
        for ($id = 1; $id <= 501; $id++) {
            $files[] = $this->file($id, 'e' . $id, self::NOW);
        }
        $this->watching(self::WATCH, $this->folder($files));
        $this->links->expects($this->once())->method('reconcileWatch')->willReturnCallback(
            static function (): array {
                usleep(50000);

                return ['process' => [], 'forget' => []];
            },
        );
        $this->index->expects($this->never())->method('markReconciled');

        $this->reconcile()->run(self::NOW, microtime(true) + 0.02, false);
    }

    public function testAWatchReconciledWithinTheIntervalWaits(): void
    {
        $this->index->method('active')->willReturn([['reconciled' => self::NOW - 1800] + self::WATCH]);
        $this->sender->expects($this->never())->method('ownerNode');

        $this->reconcile()->run(self::NOW, microtime(true) + 60, false);
    }

    public function testTheFirstPassOnlyTakesNewWatches(): void
    {
        $this->index->method('active')->willReturn([['reconciled' => self::NOW - 7200] + self::WATCH]);
        $this->sender->expects($this->never())->method('ownerNode');

        $this->reconcile()->run(self::NOW, microtime(true) + 60, true);
    }

    public function testAFailedQuestionSyncsTheOwnerAndIsTriedAgain(): void
    {
        $this->watching(self::WATCH, $this->folder([$this->file(7, 'e7', self::NOW)]));
        $this->links->method('reconcileWatch')->willThrowException(new \RuntimeException('409'));
        $this->sync->expects($this->once())->method('request')->with('ada');
        $this->index->expects($this->never())->method('markReconciled');

        $this->reconcile()->run(self::NOW, microtime(true) + 60, false);
    }

    public function testAFileThatCouldNotGoNowIsQueued(): void
    {
        $this->watching(self::WATCH, $this->folder([$this->file(7, 'e7', self::NOW - 600)]));
        $this->links->method('reconcileWatch')->willReturn(['process' => ['7'], 'forget' => []]);
        $this->sender->method('file')->willReturn(WatchSender::RETRY);
        $this->index->expects($this->once())->method('enqueueIfAbsent')->with(5, 7, '', self::NOW - 600, self::NOW + 60);
        // A change the listener queued during the send is newer and stays.
        $this->index->expects($this->never())->method('enqueue');

        $this->reconcile()->run(self::NOW, microtime(true) + 60, false);
    }

    public function testAFileStillChangingWaitsOutTheQuietPeriodInTheQueue(): void
    {
        $this->watching(self::WATCH, $this->folder([$this->file(7, 'e7', self::NOW - 10)]));
        $this->links->method('reconcileWatch')->willReturn(['process' => ['7'], 'forget' => []]);
        $this->sender->expects($this->never())->method('file');
        $this->index->expects($this->once())->method('enqueueIfAbsent')->with(5, 7, '', self::NOW - 10);
        $this->index->expects($this->never())->method('enqueue');

        $this->reconcile()->run(self::NOW, microtime(true) + 60, false);
    }

    public function testAFileAlreadyQueuedKeepsItsNewerChange(): void
    {
        $this->watching(self::WATCH, $this->folder([$this->file(7, 'e7', self::NOW - 600)]));
        $this->links->method('reconcileWatch')->willReturn(['process' => ['7'], 'forget' => []]);
        $this->index->method('queued')->with(5, 7)->willReturn(true);
        $this->sender->expects($this->never())->method('file');
        $this->index->expects($this->never())->method('enqueue');
        $this->index->expects($this->never())->method('enqueueIfAbsent');

        $this->reconcile()->run(self::NOW, microtime(true) + 60, false);
    }

    /**
     * @param array{id: int, uid: string, kind: string, target: int, state: string, since: int, reconciled: int} $watch
     */
    private function watching(array $watch, Folder $target): void
    {
        $this->index->method('active')->willReturn([$watch]);
        $this->sender->method('ownerNode')->with('ada', 30)->willReturn($target);
    }

    /**
     * @param list<\OCP\Files\Node> $children
     */
    private function folder(array $children): Folder
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getDirectoryListing')->willReturn($children);

        return $folder;
    }

    private function file(int $id, string $etag, int $mtime): File
    {
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getEtag')->willReturn($etag);
        $file->method('getMTime')->willReturn($mtime);

        return $file;
    }

    private function reconcile(): WatchReconcile
    {
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnArgument(2);
        $policy = $this->createStub(WatchPolicy::class);
        $policy->method('blocks')->willReturn($this->sharedOff);

        return new WatchReconcile(
            $this->index,
            $this->sender,
            $this->sync,
            $this->links,
            $this->createStub(OwnWrites::class),
            $config,
            new NullLogger(),
            $policy,
        );
    }
}
