<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\BackgroundJob;

use OCA\SynaplanIntegration\BackgroundJob\ReuploadJob;
use OCA\SynaplanIntegration\BackgroundJob\SourceEventsJob;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Tests\Unit\Support\ArraySourceEventQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCA\SynaplanIntegration\Service\SourceEventQueue;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SourceEventsJobTest extends TestCase
{
    private PlatformLinkService&MockObject $links;
    private IJobList&MockObject $jobs;
    private ILockingProvider&MockObject $locks;
    private ArraySourceEventQueue $queue;
    private int $now = 1_000;
    /** @var list<array{string, list<array<string, string>>}> */
    private array $sent = [];
    /** @var list<array{string, mixed, int|null}> */
    private array $jobsAdded = [];

    protected function setUp(): void
    {
        $this->links = $this->createMock(PlatformLinkService::class);
        $this->jobs = $this->createMock(IJobList::class);
        $this->locks = $this->createMock(ILockingProvider::class);
        $this->queue = new ArraySourceEventQueue();
        $this->jobs->method('add')->willReturnCallback(function (string $job, mixed $argument): void {
            $this->jobsAdded[] = [$job, $argument, null];
        });
        $this->jobs->method('scheduleAfter')->willReturnCallback(function (string $job, int $after, mixed $argument): void {
            $this->jobsAdded[] = [$job, $argument, $after];
        });
    }

    /**
     * @param callable(list<array<string, string>>, string): array<string, mixed> $answer
     */
    private function synaplanAnswers(callable $answer): void
    {
        $this->links->method('reportSourceEvents')->willReturnCallback(function (array $events, string $uid) use ($answer): array {
            $this->sent[] = [$uid, $events];

            return $answer($events, $uid) + ['applied' => 0, 'ignored' => 0, 'reupload' => []];
        });
    }

    private function queue(string $uid, string $id, string $event, int $ord, string $path = ''): void
    {
        $this->queue->add($uid, ['id' => $id, 'event' => $event, 'version' => 'e1', 'path' => $path], $ord);
    }

    /**
     * @param array<string, mixed> $argument
     */
    private function runJob(array $argument): void
    {
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $job = new SourceEventsJob($time, $this->links, $this->jobs, $this->queue, $this->locks, new NullLogger());
        (new \ReflectionMethod(SourceEventsJob::class, 'run'))->invoke($job, $argument);
    }

    /**
     * @return list<string>
     */
    private function sentEvents(): array
    {
        $out = [];
        foreach ($this->sent as [$uid, $events]) {
            foreach ($events as $event) {
                $out[] = $uid . ' ' . $event['event'] . ' ' . $event['id'];
            }
        }

        return $out;
    }

    public function testAPersonsEventsGoOutInOrderInOneCallAndKeptCopiesAreQueued(): void
    {
        $this->queue('ada', '42', 'moved', 20, 'Specs/spec.pdf');
        $this->queue('ada', '42', 'changed', 10, 'Old/spec.pdf');
        $this->synaplanAnswers(static fn (): array => ['applied' => 2, 'reupload' => [['external_id' => 'ada', 'id' => '42']]]);

        $this->runJob(['uid' => 'ada']);

        self::assertSame([['ada', [
            ['id' => '42', 'event' => 'changed', 'version' => 'e1', 'path' => 'Old/spec.pdf'],
            ['id' => '42', 'event' => 'moved', 'version' => 'e1', 'path' => 'Specs/spec.pdf'],
        ]]], $this->sent);
        self::assertSame([], $this->queue->rows);
        self::assertSame([[ReuploadJob::class, ['uid' => 'ada', 'id' => '42'], null]], $this->jobsAdded);
    }

    public function testARestoreNeverOvertakesTheDeletionSynaplanCouldNotTakeYet(): void
    {
        $this->queue('ada', '42', 'deleted', 10);
        $this->synaplanAnswers(fn (): array => $this->now < 1_060 ? ['retry' => true] : ['applied' => 1]);

        $this->runJob(['uid' => 'ada']);
        $this->queue('ada', '42', 'restored', 20);
        $this->now += 30;
        $this->runJob(['uid' => 'ada']);

        self::assertSame(['ada deleted 42'], $this->sentEvents(), 'the restore waits behind the deletion');
        self::assertSame([SourceEventsJob::class, ['uid' => 'ada'], 1_060], $this->jobsAdded[array_key_last($this->jobsAdded)]);

        $this->now = 1_060;
        $this->runJob(['uid' => 'ada']);

        self::assertSame(['ada deleted 42', 'ada deleted 42', 'ada restored 42'], $this->sentEvents());
        self::assertSame([], $this->queue->rows);
    }

    public function testEachRetryWaitsLongerAndTheLastGivesTheEventsUp(): void
    {
        $this->queue('ada', '42', 'changed', 10);
        $this->synaplanAnswers(static fn (): array => ['retry' => true]);

        $waits = [];
        foreach (SourceEventsJob::RETRY_DELAYS as $ignored) {
            $this->runJob(['uid' => 'ada']);
            $row = array_values($this->queue->rows)[0];
            $waits[] = $row['due'] - $this->now;
            $this->now = $row['due'];
        }
        self::assertSame(SourceEventsJob::RETRY_DELAYS, $waits);

        $this->runJob(['uid' => 'ada']);

        self::assertSame([], $this->queue->rows);
        self::assertCount(count(SourceEventsJob::RETRY_DELAYS) + 1, $this->sent);
    }

    public function testAnotherPersonsEventsDoNotWaitForARetry(): void
    {
        $this->queue('ada', '42', 'deleted', 10);
        $this->queue('bob', '42', 'deleted', 10);
        $this->synaplanAnswers(static fn (array $events, string $uid): array => $uid === 'ada' ? ['retry' => true] : ['applied' => 1]);

        $this->runJob(['uid' => 'ada']);
        $this->runJob(['uid' => 'bob']);

        self::assertSame(['ada deleted 42', 'bob deleted 42'], $this->sentEvents());
        self::assertSame(['ada'], array_values(array_map(static fn (array $row): string => $row['uid'], $this->queue->rows)));
    }

    public function testAMovedFolderIsWalkedPageByPageAndItsFilesFollowIt(): void
    {
        $this->queue->cache(3, 'files/Archive/Specs', 50);
        for ($id = 1; $id <= SourceEventsJob::WALK_PAGE + 1; ++$id) {
            $this->queue->cache(3, 'files/Archive/Specs/f' . $id . '.pdf', 100 + $id);
        }
        $this->queue('ada', '50', 'moved', 10, 'Archive/Specs');
        $this->queue->add('ada', ['id' => '50', 'event' => 'moved', 'version' => 'e1', 'path' => 'Archive/Specs'], 10, SourceEventQueue::STEP_WALK);
        $this->queue('ada', '7', 'changed', 20, 'later.txt');
        $this->synaplanAnswers(static fn (): array => ['applied' => 1]);

        $this->runJob(['uid' => 'ada']);

        $sent = $this->sentEvents();
        self::assertCount(SourceEventsJob::WALK_PAGE + 3, $sent);
        self::assertSame('ada moved 50', $sent[0]);
        self::assertSame('ada moved 101', $sent[1]);
        self::assertSame('ada changed 7', $sent[array_key_last($sent)]);
        self::assertSame('Archive/Specs/f1.pdf', $this->sent[1][1][0]['path']);
        self::assertSame(2, $this->queue->cacheReads);
        self::assertSame([], $this->queue->rows);
    }

    public function testRowsOfADeletionUnderWayWaitAndAreDroppedWhenItNeverHappens(): void
    {
        $this->now = 10_000;
        $this->queue->add('ada', ['id' => '51', 'event' => 'deleted', 'version' => 'e1', 'path' => ''], 9_990 * 1_000_000, SourceEventQueue::STEP_HELD, 50);
        $this->queue('ada', '7', 'changed', 9_995 * 1_000_000);
        $this->synaplanAnswers(static fn (): array => ['applied' => 1]);

        $this->runJob(['uid' => 'ada']);

        self::assertSame([], $this->sent);
        self::assertSame([SourceEventsJob::class, ['uid' => 'ada'], 10_060], $this->jobsAdded[array_key_last($this->jobsAdded)]);

        $this->now += SourceEventsJob::HELD_FOR;
        $this->runJob(['uid' => 'ada']);

        self::assertSame(['ada changed 7'], $this->sentEvents());
    }

    public function testAJobQueuedBeforeTheQueueSendsItsEvent(): void
    {
        $this->synaplanAnswers(static fn (): array => ['applied' => 1]);

        $this->runJob(['uid' => 'ada', 'id' => '42', 'event' => 'changed', 'version' => 'e2', 'path' => 'Specs/spec.pdf']);

        self::assertSame([['ada', [['id' => '42', 'event' => 'changed', 'version' => 'e2', 'path' => 'Specs/spec.pdf']]]], $this->sent);
    }

    public function testWhileAnotherRunSendsThePersonsEventsThisOneComesBackInAMinute(): void
    {
        $this->queue('ada', '42', 'changed', 10);
        $this->locks->method('acquireLock')->willThrowException(new LockedException('synaplan_integration/source-events/ada'));
        $this->links->expects($this->never())->method('reportSourceEvents');

        $this->runJob(['uid' => 'ada']);

        self::assertSame([[SourceEventsJob::class, ['uid' => 'ada'], 1_060]], $this->jobsAdded);
    }

    public function testAJobWithoutAPersonSendsNothing(): void
    {
        $this->links->expects($this->never())->method('reportSourceEvents');

        $this->runJob(['id' => '42', 'event' => 'changed', 'version' => 'e2', 'path' => 'Specs/spec.pdf']);
    }
}
