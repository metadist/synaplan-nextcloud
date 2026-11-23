<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\BackgroundJob;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\SourceEventQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Sends one person's source events to Synaplan, in the order they happened,
 * and queues a re-upload for each copy that is kept up to date. An event
 * that could not be sent for now is tried again later and holds back the
 * events after it.
 *
 * @phpstan-import-type QueuedEvent from SourceEventQueue
 */
class SourceEventsJob extends QueuedJob
{
    /** Events per call; Synaplan takes up to 500. */
    public const BATCH = 100;
    /** Seconds to wait before each retry; after the last, the events are given up. */
    public const RETRY_DELAYS = [60, 300, 900, 3600];
    /** Seconds of work per run before the job hands over to its next run. */
    private const BUDGET = 60;
    /** Files of a moved folder queued per step of its walk. */
    public const WALK_PAGE = 500;
    /** Seconds after which the held rows of a deletion that never happened go. */
    public const HELD_FOR = 600;

    public function __construct(
        ITimeFactory $time,
        private PlatformLinkService $links,
        private IJobList $jobs,
        private SourceEventQueue $queue,
        private ILockingProvider $locks,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    protected function run(mixed $argument): void
    {
        if (!is_array($argument)) {
            return;
        }
        $uid = is_string($argument['uid'] ?? null) ? $argument['uid'] : '';
        if ($uid === '') {
            return;
        }
        $this->takeOverQueuedEvent($uid, $argument);

        $lock = 'synaplan_integration/source-events/' . $uid;
        try {
            $this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            $this->jobs->scheduleAfter(self::class, $this->time->getTime() + 60, ['uid' => $uid]);

            return;
        }
        try {
            $this->drain($uid);
        } finally {
            $this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    private function drain(string $uid): void
    {
        $until = $this->time->getTime() + self::BUDGET;
        while (true) {
            $rows = $this->queue->head($uid, self::BATCH);
            if ($rows === []) {
                return;
            }
            $now = $this->time->getTime();
            if ($rows[0]['due'] > $now) {
                $this->jobs->scheduleAfter(self::class, $rows[0]['due'], ['uid' => $uid]);

                return;
            }
            $head = $rows[0];
            if ($head['step'] === SourceEventQueue::STEP_WALK) {
                $this->walk($head);
            } elseif ($head['step'] === SourceEventQueue::STEP_HELD) {
                if ($now * 1_000_000 - $head['ord'] < self::HELD_FOR * 1_000_000) {
                    $this->jobs->scheduleAfter(self::class, $now + 60, ['uid' => $uid]);

                    return;
                }
                $this->queue->removeHeld($uid, $head['walked']);
            } elseif ($head['step'] === SourceEventQueue::STEP_SEND) {
                $this->send($uid, $this->leadingSends($rows), $now);
            } else {
                $this->queue->remove([$head['seq']]);
            }
            if ($this->time->getTime() >= $until) {
                $this->jobs->add(self::class, ['uid' => $uid]);

                return;
            }
        }
    }

    /**
     * Queue the next page of a moved folder's files and folders, with the
     * paths the person sees them at, behind the walk and with its order.
     *
     * @param QueuedEvent $walk
     */
    private function walk(array $walk): void
    {
        $page = $this->queue->descendants($walk['file_id'], $walk['walked'], self::WALK_PAGE);
        foreach ($page as $entry) {
            $path = $walk['path'] === '' ? '' : $walk['path'] . '/' . $entry['path'];
            $this->queue->add($walk['uid'], [
                'id' => (string) $entry['id'],
                'event' => $walk['event'],
                'version' => substr($entry['etag'], 0, 255),
                'path' => strlen($path) <= 255 ? $path : '',
            ], $walk['ord']);
        }
        if (count($page) < self::WALK_PAGE) {
            $this->queue->remove([$walk['seq']]);

            return;
        }
        $this->queue->walked($walk['seq'], $page[count($page) - 1]['id']);
    }

    /**
     * @param list<QueuedEvent> $rows
     * @return list<QueuedEvent>
     */
    private function leadingSends(array $rows): array
    {
        $batch = [];
        foreach ($rows as $row) {
            if ($row['step'] !== SourceEventQueue::STEP_SEND) {
                break;
            }
            $batch[] = $row;
        }

        return $batch;
    }

    /**
     * @param non-empty-list<QueuedEvent> $batch
     */
    private function send(string $uid, array $batch, int $now): void
    {
        $answer = $this->links->reportSourceEvents(array_map(static fn (array $row): array => [
            'id' => (string) $row['file_id'],
            'event' => $row['event'],
            'version' => $row['version'],
            'path' => $row['path'],
        ], $batch), $uid);
        $seqs = array_map(static fn (array $row): int => $row['seq'], $batch);

        if (($answer['retry'] ?? false) === true) {
            $attempts = $batch[0]['attempts'] + 1;
            if ($attempts <= count(self::RETRY_DELAYS)) {
                $this->queue->retryLater($seqs, $attempts, $now + self::RETRY_DELAYS[$attempts - 1]);

                return;
            }
            $this->logger->warning('Gave up telling Synaplan of {count} file events of {uid}', [
                'app' => Application::APP_ID,
                'count' => count($seqs),
                'uid' => $uid,
            ]);
        }
        $this->queue->remove($seqs);
        foreach ($answer['reupload'] as $entry) {
            $this->jobs->add(ReuploadJob::class, [
                'uid' => $entry['external_id'],
                'id' => $entry['id'],
            ]);
        }
    }

    /**
     * A job queued before the queue carried one event in its argument.
     *
     * @param array<mixed> $argument
     */
    private function takeOverQueuedEvent(string $uid, array $argument): void
    {
        $id = is_string($argument['id'] ?? null) ? $argument['id'] : '';
        $event = is_string($argument['event'] ?? null) ? $argument['event'] : '';
        if ($id === '' || $event === '') {
            return;
        }
        $this->queue->add($uid, [
            'id' => $id,
            'event' => $event,
            'version' => is_string($argument['version'] ?? null) ? $argument['version'] : '',
            'path' => is_string($argument['path'] ?? null) ? $argument['path'] : '',
        ], SourceEventQueue::now());
    }
}
