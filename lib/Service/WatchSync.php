<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Keeps the watch index in step with Synaplan, one person at a time, with
 * that person's own credential: when Synaplan says the person's watches
 * changed, every ten minutes for the people with watches here, and once a
 * day for everyone who uses Synaplan, which finds watches started elsewhere
 * whose notice was missed.
 */
class WatchSync
{
    public const PENDING = 'watch_sync_pending';
    private const ROUND_AT = 'watch_sync_round';
    private const FULL_AT = 'watch_sync_full';
    private const ROUND_SECONDS = 600;
    private const FULL_SECONDS = 86400;

    public function __construct(
        private WatchIndex $index,
        private PlatformLinkService $links,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Synaplan said this person's watches changed: sync them on the next run.
     */
    public function request(string $uid): void
    {
        if ($uid !== '') {
            $this->config->setUserValue($uid, Application::APP_ID, self::PENDING, '1');
        }
    }

    /**
     * True when the person's rows now match Synaplan's list.
     */
    public function person(string $uid, int $now): bool
    {
        $this->config->deleteUserValue($uid, Application::APP_ID, self::PENDING);
        if (!$this->links->canActFor($uid)) {
            $this->index->forget($uid);

            return true;
        }
        try {
            $this->index->replace($uid, $this->links->listWatches($uid), $now);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Could not list the Synaplan watches of {uid}: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function run(int $now): void
    {
        $people = $this->config->getUsersForUserValue(Application::APP_ID, self::PENDING, '1');
        if ($now - (int) $this->config->getAppValue(Application::APP_ID, self::ROUND_AT, '0') >= self::ROUND_SECONDS) {
            $this->config->setAppValue(Application::APP_ID, self::ROUND_AT, (string) $now);
            $people = [...$people, ...$this->index->uids()];
        }
        if ($now - (int) $this->config->getAppValue(Application::APP_ID, self::FULL_AT, '0') >= self::FULL_SECONDS) {
            $this->config->setAppValue(Application::APP_ID, self::FULL_AT, (string) $now);
            foreach ($this->config->getUsersForUserValue(Application::APP_ID, 'ai_consent', '1') as $uid) {
                if ($this->links->canActFor($uid)) {
                    $people[] = $uid;
                }
            }
        }
        foreach (array_values(array_unique($people)) as $uid) {
            $this->person($uid, $now);
        }
    }
}
