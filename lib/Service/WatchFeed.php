<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\IConfig;

/**
 * Sends the files that have been quiet long enough to their watch. A file
 * that keeps changing is pushed back by each change (the listener), so a
 * desktop client saving five times makes one run.
 *
 * @phpstan-import-type QueueRow from WatchIndex
 */
class WatchFeed
{
    public const QUIET_SECONDS = 60;
    /** Hidden, for the journeys: how long a file must be quiet. */
    public const QUIET_KEY = 'watch_quiet_seconds';
    private const BATCH = 200;
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private WatchIndex $index,
        private WatchSender $sender,
        private WatchSync $sync,
        private IConfig $config,
    ) {
    }

    public function quietSeconds(): int
    {
        return self::quietSecondsIn($this->config);
    }

    public static function quietSecondsIn(IConfig $config): int
    {
        $value = (int) $config->getAppValue(Application::APP_ID, self::QUIET_KEY, (string) self::QUIET_SECONDS);

        return max(0, min(3600, $value));
    }

    public function run(int $now, float $deadline): void
    {
        foreach ($this->index->due($now, $this->quietSeconds(), self::BATCH) as $row) {
            if (microtime(true) > $deadline) {
                return;
            }
            $watch = $this->index->find($row['watch']);
            if ($watch === null) {
                $this->index->dequeue($row['watch'], $row['file']);
                continue;
            }
            if ($row['event'] !== '') {
                $result = $this->sender->targetEvent($watch, $row['event'], $now);
                if ($result === WatchSender::SENT) {
                    $this->sync->request($watch['uid']);
                }
            } elseif ($watch['state'] !== WatchIndex::ACTIVE) {
                $result = WatchSender::DROP;
            } else {
                $result = $this->sender->file($watch, $row['file']);
            }
            $this->settle($watch, $row, $result, $now);
        }
    }

    /**
     * @param array{id: int, uid: string} $watch
     * @param QueueRow $row
     */
    private function settle(array $watch, array $row, string $result, int $now): void
    {
        if ($result === WatchSender::RETRY && $row['attempts'] + 1 < self::MAX_ATTEMPTS) {
            $this->index->retryLater($row, $now + 60 * (2 ** $row['attempts']), $row['attempts'] + 1);

            return;
        }
        $this->index->done($row);
        if ($result === WatchSender::NOT_RUNNING) {
            $this->sync->request($watch['uid']);
        }
    }
}
