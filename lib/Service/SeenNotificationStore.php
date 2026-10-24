<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

/**
 * Remembers a notification id for ten minutes. True means it was already accepted.
 */
interface SeenNotificationStore
{
    public function alreadySeen(string $type, string $id): bool;
}
