<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\BackgroundJob;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\ShareRecipients;
use OCA\SynaplanIntegration\Service\SourceEventQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Sends one person's source events to Synaplan, in the order they happened,
 * and queues a re-upload for each copy that is kept up to date. An event
 * that could not be sent for now is tried again later and holds back the
 * events after it. A person's events go to the people the file is shared
 * with first, and no one's event goes out while an older one of someone
 * else has yet to: it may join their queue ahead of it.
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
        private IRootFolder $rootFolder,
        private ShareRecipients $recipients,
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
        while (($shares = $this->queue->shares($uid, self::BATCH)) !== []) {
            foreach ($shares as $row) {
                $this->share($row);
                $this->queue->remove([$row['seq']]);
            }
            if ($this->time->getTime() >= $until) {
                $this->jobs->add(self::class, ['uid' => $uid]);

                return;
            }
        }
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
                $this->queue->removeHeld($uid, $head['walked'], $head['ord']);
            } elseif ($head['step'] === SourceEventQueue::STEP_SHARE) {
                $this->share($head);
                $this->queue->remove([$head['seq']]);
            } elseif ($head['step'] === SourceEventQueue::STEP_SEND) {
                $batch = $this->beforeOthersShares($uid, $this->leadingSends($rows, $now));
                if ($batch === []) {
                    $this->jobs->scheduleAfter(self::class, $now + 30, ['uid' => $uid]);

                    return;
                }
                $this->send($uid, $batch, $now);
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
     * The rows older than any event of someone else that has yet to go to
     * the people the file is shared with: it may join this queue ahead of
     * the rest. That person's job is asked to run.
     *
     * @param non-empty-list<QueuedEvent> $batch
     * @return list<QueuedEvent>
     */
    private function beforeOthersShares(string $uid, array $batch): array
    {
        $share = $this->queue->oldestShare($uid);
        if ($share === null || $share['ord'] > $batch[array_key_last($batch)]['ord']) {
            return $batch;
        }
        $this->jobs->add(self::class, ['uid' => $share['uid']]);

        return array_values(array_filter($batch, static fn (array $row): bool => $row['ord'] < $share['ord']));
    }

    /**
     * Queue the event for each person the file is shared with, with the path
     * they see it at, in their own queue and with the same order.
     *
     * @param QueuedEvent $row
     */
    private function share(array $row): void
    {
        $node = $this->nodeOf($row['uid'], $row['file_id']);
        if ($node === null) {
            return;
        }
        try {
            $owner = (string) $node->getOwner()?->getUID();
        } catch (\Throwable) {
            $owner = '';
        }
        $event = ['id' => (string) $row['file_id'], 'event' => $row['event'], 'version' => $row['version']];
        foreach ($this->recipients->of($node, [$row['uid'], $owner]) as $uid) {
            $theirs = $this->nodeOf($uid, $row['file_id']);
            if ($theirs === null || ($row['event'] === 'moved' && self::isMountRoot($theirs))) {
                continue;
            }
            $path = preg_match('#^/[^/]+/files/(.+)$#', $theirs->getPath(), $match) === 1 ? trim($match[1], '/') : '';
            $this->queue->add($uid, $event + ['path' => strlen($path) <= 255 ? $path : ''], $row['ord']);
            if ($theirs instanceof Folder && $row['event'] === 'moved') {
                $this->queue->add($uid, $event + ['path' => strlen($path) <= 255 ? $path : ''], $row['ord'], SourceEventQueue::STEP_WALK);
            } elseif ($theirs instanceof Folder && $row['event'] === 'restored') {
                $this->queue->addDescendants($uid, $row['file_id'], 'restored', $row['ord'], SourceEventQueue::STEP_SEND);
            }
            $this->jobs->add(self::class, ['uid' => $uid]);
        }
    }

    /**
     * The root of a mount, such as a share as its recipient sees it: it
     * keeps its path for them when its owner moves it.
     */
    private static function isMountRoot(Node $node): bool
    {
        try {
            return $node->getInternalPath() === '';
        } catch (\Throwable) {
            return false;
        }
    }

    private function nodeOf(string $uid, int $id): ?Node
    {
        try {
            $node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($id);
        } catch (\Throwable) {
            return null;
        }

        return $node instanceof Node ? $node : null;
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
     * The sends at the head that are due; a row waiting for its retry
     * holds back the rows after it.
     *
     * @param non-empty-list<QueuedEvent> $rows
     * @return non-empty-list<QueuedEvent>
     */
    private function leadingSends(array $rows, int $now): array
    {
        $batch = [$rows[0]];
        foreach (array_slice($rows, 1) as $row) {
            if ($row['step'] !== SourceEventQueue::STEP_SEND || $row['due'] > $now) {
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
