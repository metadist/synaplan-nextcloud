<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\ICacheFactory;

/**
 * Notification ids already accepted, remembered for ten minutes. A repeat is
 * refused instead of claimed a second time.
 */
class SeenNotifications
{
    private const TTL = 600;

    public function __construct(
        private ICacheFactory $cacheFactory,
    ) {
    }

    public function alreadySeen(string $type, string $id): bool
    {
        $cache = $this->cacheFactory->createDistributed(Application::APP_ID);
        $key = 'seen_' . hash('sha256', $type . '|' . $id);
        if ($cache->get($key) !== null) {
            return true;
        }
        $cache->set($key, '1', self::TTL);

        return false;
    }
}
