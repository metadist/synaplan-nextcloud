<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Lists each active watch's target as its owner sees it and asks Synaplan
 * which files it still has to get: this catches missed events, storage
 * without events, the time the app was off and files that arrived while the
 * watch was paused. A new watch gets its first pass right away; that pass
 * sends the files already there when the person asked for them.
 *
 * A folder watch looks at the files directly in its folder, like the
 * OpenCloud service. Only files changed after the app started feeding the
 * watch count, unless the person asked for the files already there. A file
 * that changed within the quiet period goes through the feed's queue, like
 * one the listener saw.
 *
 * @phpstan-import-type WatchRow from WatchIndex
 */
class WatchReconcile
{
    public const INTERVAL_KEY = 'watch_reconcile_minutes';
    private const DEFAULT_MINUTES = 60;
    private const BATCH = 500;

    public function __construct(
        private WatchIndex $index,
        private WatchSender $sender,
        private WatchSync $sync,
        private PlatformLinkService $links,
        private OwnWrites $ownWrites,
        private IConfig $config,
        private LoggerInterface $logger,
        private WatchPolicy $policy,
    ) {
    }

    public function intervalSeconds(): int
    {
        $minutes = (int) $this->config->getAppValue(Application::APP_ID, self::INTERVAL_KEY, (string) self::DEFAULT_MINUTES);

        return 60 * max(15, min(1440, $minutes));
    }

    /**
     * @param bool $newOnly only the first pass of watches never reconciled
     */
    public function run(int $now, float $deadline, bool $newOnly): void
    {
        $interval = $this->intervalSeconds();
        foreach ($this->index->active() as $watch) {
            if (microtime(true) > $deadline) {
                return;
            }
            $first = $watch['reconciled'] === 0;
            if ($newOnly ? !$first : (!$first && $now - $watch['reconciled'] < $interval)) {
                continue;
            }
            if ($this->watch($watch, $now, $deadline)) {
                $this->index->markReconciled($watch['id'], $now);
            }
        }
        if (!$newOnly) {
            $this->ownWrites->prune($now);
        }
    }

    /**
     * How many files the next pass would send: on a paused watch, the files
     * that arrived while it was paused. Synaplan's answer changes nothing.
     *
     * @param WatchRow $watch
     */
    public function waiting(array $watch): int
    {
        $count = 0;
        foreach (array_chunk(array_map(WatchSender::version(...), $this->candidates($watch)), self::BATCH, true) as $batch) {
            $answer = $this->links->reconcileWatch($watch['uid'], $watch['id'], self::items($batch));
            $count += count(array_filter($answer['process'], static fn (string $id): bool => isset($batch[$id])));
        }

        return $count;
    }

    /**
     * @param WatchRow $watch
     * @return bool false when the pass has to be tried again
     */
    private function watch(array $watch, int $now, float $deadline): bool
    {
        $quiet = WatchFeed::quietSecondsIn($this->config);
        $files = $this->candidates($watch);
        foreach (array_chunk(array_map(WatchSender::version(...), $files), self::BATCH, true) as $batch) {
            try {
                $answer = $this->links->reconcileWatch($watch['uid'], $watch['id'], self::items($batch));
            } catch (\Throwable $e) {
                $this->logger->warning('Could not reconcile Synaplan watch {watch}: {message}', [
                    'app' => Application::APP_ID,
                    'watch' => $watch['id'],
                    'message' => $e->getMessage(),
                ]);
                $this->sync->request($watch['uid']);

                return false;
            }
            foreach ($answer['process'] as $id) {
                if (!isset($batch[$id])) {
                    continue;
                }
                $fileId = (int) $id;
                // A queued file is the feed's: its newer change keeps its quiet period.
                if ($this->index->queued($watch['id'], $fileId)) {
                    continue;
                }
                $changed = (int) $files[$fileId]->getMTime();
                if ($changed > $now - $quiet || microtime(true) > $deadline) {
                    $this->index->enqueueIfAbsent($watch['id'], $fileId, '', min($changed, $now));
                    continue;
                }
                $result = $this->sender->file($watch, $fileId);
                if ($result === WatchSender::NOT_RUNNING) {
                    $this->sync->request($watch['uid']);

                    return true;
                }
                if ($result === WatchSender::RETRY) {
                    $this->index->enqueueIfAbsent($watch['id'], $fileId, '', $changed, $now + 60);
                }
            }
        }

        return true;
    }

    /**
     * The target's files that changed or arrived after the app started
     * feeding the watch, by id. None while the target is gone or a setting
     * holds it.
     *
     * @param WatchRow $watch
     * @return array<int, File>
     */
    private function candidates(array $watch): array
    {
        $target = $this->sender->ownerNode($watch['uid'], $watch['target']);
        if ($target === null || $this->policy->blocks($target)) {
            return [];
        }
        // A queued file arrived after the start, whatever its time says: moved in, say.
        $queued = array_flip($this->index->queuedFiles($watch['id']));
        $files = [];
        foreach ($this->files($target) as $file) {
            if ($watch['since'] === 0 || $file->getMTime() > $watch['since'] || isset($queued[(int) $file->getId()])) {
                $files[(int) $file->getId()] = $file;
            }
        }

        return $files;
    }

    /**
     * @param array<int, string> $batch
     * @return list<array{id: string, version: string}>
     */
    private static function items(array $batch): array
    {
        $items = [];
        foreach ($batch as $id => $version) {
            $items[] = ['id' => (string) $id, 'version' => $version];
        }

        return $items;
    }

    /**
     * @return list<File>
     */
    private function files(File|Folder $target): array
    {
        if ($target instanceof File) {
            return [$target];
        }
        try {
            return array_values(array_filter($target->getDirectoryListing(), static fn ($node): bool => $node instanceof File));
        } catch (\Throwable) {
            return [];
        }
    }
}
