<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\IConfig;

/**
 * Synaplan's notification issuer and keys, read from its two public
 * documents through the address this Nextcloud reaches Synaplan by. Refreshed
 * when this Nextcloud registers, when an administrator saves the address, and
 * hourly. A notification never triggers a refresh, so a forged key id cannot
 * make this Nextcloud call Synaplan.
 */
class NotifyKeyCache
{
    private const KEYS = 'notify_keys';
    private const ISSUER = 'notify_issuer';

    public function __construct(
        private IConfig $config,
        private PlatformLinkService $links,
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
