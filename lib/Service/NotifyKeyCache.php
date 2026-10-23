<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\IConfig;

/**
 * Synaplan's notify keys, refreshed when this Nextcloud registers, when an
 * administrator saves the address, and hourly. A notification never triggers
 * a refresh, so a forged key id cannot make this Nextcloud call Synaplan.
 */
class NotifyKeyCache
{
    private const KEYS = 'notify_keys';

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

    public function refresh(): void
    {
        if ($this->config->getAppValue(Application::APP_ID, 'link_instance_id', '') === '') {
            return;
        }
        try {
            $keys = $this->links->notifyKeys();
        } catch (\Throwable) {
            return;
        }
        $this->config->setAppValue(
            Application::APP_ID,
            self::KEYS,
            json_encode($keys, JSON_THROW_ON_ERROR),
        );
    }
}
