<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Synaplan's notification issuer and keys, read from its two public
 * documents through the address this Nextcloud reaches Synaplan by. Refreshed
 * when this Nextcloud registers, when an administrator saves the address,
 * hourly, and after a notification no known key verifies, at most once a
 * minute, so forged notifications cannot make this Nextcloud call Synaplan
 * more often than that.
 */
class NotifyKeyCache
{
    private const KEYS = 'notify_keys';
    public const ISSUER = 'notify_issuer';
    private const UNKNOWN_KEY_CHECKED_AT = 'notify_keys_unknown_checked_at';
    private const UNKNOWN_KEY_INTERVAL = 60;
    private const UNKNOWN_KEY_LOCK = Application::APP_ID . '/notify-keys-unknown';
    private const LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private IConfig $config,
        private IAppConfig $appConfig,
        private PlatformLinkService $links,
        private ILockingProvider $locks,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function keys(): array
    {
        $raw = (string) $this->config->getAppValue(Application::APP_ID, self::KEYS, '');
        $decoded = $raw === '' ? [] : json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    public function issuer(): string
    {
        return (string) $this->config->getAppValue(Application::APP_ID, self::ISSUER, '');
    }

    public function refresh(): void
    {
        if ($this->config->getAppValue(Application::APP_ID, 'link_instance_id', '') === '') {
            return;
        }
        $this->refreshTrust();
    }

    /**
     * A notification no known key verifies may be signed with a key Synaplan
     * started using since the last refresh. Returns whether the keys changed
     * since this request read them, also when another request refreshed them.
     */
    public function refreshForUnknownKey(int $now): bool
    {
        $before = $this->keys();
        if (!$this->acquire()) {
            return false;
        }
        try {
            // Concurrent requests claim the interval one at a time, each
            // reading the claim the one before it wrote.
            $this->appConfig->clearCache();
            $checkedAt = (int) $this->config->getAppValue(Application::APP_ID, self::UNKNOWN_KEY_CHECKED_AT, '0');
            if ($now - $checkedAt >= self::UNKNOWN_KEY_INTERVAL) {
                $this->config->setAppValue(Application::APP_ID, self::UNKNOWN_KEY_CHECKED_AT, (string) $now);
                $this->refresh();
            }

            return $this->keys() !== $before;
        } finally {
            $this->locks->releaseLock(self::UNKNOWN_KEY_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    private function acquire(): bool
    {
        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        while (true) {
            try {
                $this->locks->acquireLock(self::UNKNOWN_KEY_LOCK, ILockingProvider::LOCK_EXCLUSIVE);

                return true;
            } catch (LockedException) {
                if (microtime(true) >= $deadline) {
                    return false;
                }
                usleep(100_000);
            }
        }
    }

    /**
     * Read Synaplan's issuer and keys now, registered or not: registering
     * needs the issuer to address its first client assertion.
     */
    public function refreshTrust(): void
    {
        try {
            $trust = $this->links->notificationTrust();
        } catch (\Throwable) {
            return;
        }
        // A half answer keeps the last good trust: without keys every
        // notification would be refused until the next refresh.
        if ($trust['issuer'] === '' || $trust['keys'] === []) {
            return;
        }
        $this->config->setAppValue(Application::APP_ID, self::ISSUER, $trust['issuer']);
        $this->config->setAppValue(
            Application::APP_ID,
            self::KEYS,
            json_encode($trust['keys'], JSON_THROW_ON_ERROR),
        );
    }
}
