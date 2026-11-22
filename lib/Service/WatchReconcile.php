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
 * watch count, unless the person asked for the files already there.
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
     * @param WatchRow $watch
     * @return bool false when the pass has to be tried again
     */
    private function watch(array $watch, int $now, float $deadline): bool
    {
        $target = $this->sender->ownerNode($watch['uid'], $watch['target']);
        if ($target === null || $this->policy->blocks($target)) {
            return true;
        }
        $versions = [];
        foreach ($this->files($target) as $file) {
            if ($watch['since'] === 0 || $file->getMTime() > $watch['since']) {
                $versions[(string) $file->getId()] = WatchSender::version($file);
            }
        }
        foreach (array_chunk($versions, self::BATCH, true) as $batch) {
            $items = [];
            foreach ($batch as $id => $version) {
                $items[] = ['id' => (string) $id, 'version' => $version];
            }
            try {
                $answer = $this->links->reconcileWatch($watch['uid'], $watch['id'], $items);
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
                if (microtime(true) > $deadline) {
                    $this->index->enqueue($watch['id'], (int) $id, '', 0);
                    continue;
                }
                $result = $this->sender->file($watch, (int) $id);
                if ($result === WatchSender::NOT_RUNNING) {
                    $this->sync->request($watch['uid']);

                    return true;
                }
                if ($result === WatchSender::RETRY) {
                    $this->index->enqueue($watch['id'], (int) $id, '', 0, $now + 60);
                }
            }
        }

        return true;
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
