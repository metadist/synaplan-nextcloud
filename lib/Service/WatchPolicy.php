<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\Share\IManager as IShareManager;

/**
 * The administrator's "Allow watching shared folders". A folder or a file
 * counts as shared when someone shared it with the person, or when it or a
 * folder above it is shared with anyone else. While the setting is off, no
 * watch can start on one, and the watches already on one get no files.
 */
class WatchPolicy
{
    public const SHARED_KEY = 'watch_shared_folders';

    public function __construct(
        private IConfig $config,
        private IShareManager $shares,
        private IRootFolder $rootFolder,
    ) {
    }

    public static function sharedAllowedIn(IConfig $config): bool
    {
        return $config->getAppValue(Application::APP_ID, self::SHARED_KEY, 'yes') !== 'no';
    }

    public function sharedAllowed(): bool
    {
        return self::sharedAllowedIn($this->config);
    }

    public function blocks(Node $node): bool
    {
        return !$this->sharedAllowed() && $this->isShared($node);
    }

    public function isShared(Node $node): bool
    {
        try {
            if ($node->isShared()) {
                return true;
            }
            $access = $this->shares->getAccessList($node);
        } catch (\Throwable) {
            return false;
        }
        $users = array_unique($access['users'] ?? []);

        return count($users) > 1
            || ($access['remote'] ?? false) === true
            || ($access['public'] ?? false) === true
            || ($access['mail'] ?? []) !== [];
    }

    /**
     * Mark the watches the setting has stopped, as their owner sees them.
     *
     * @param list<array<string, mixed>> $watches rows with a `target_id`
     * @return list<array<string, mixed>>
     */
    public function markStopped(string $uid, array $watches): array
    {
        if ($this->sharedAllowed() || $watches === []) {
            return $watches;
        }
        try {
            $home = $this->rootFolder->getUserFolder($uid);
        } catch (\Throwable) {
            return $watches;
        }
        foreach ($watches as $i => $watch) {
            $target = $watch['target_id'] ?? null;
            if (!is_scalar($target) || !ctype_digit((string) $target)) {
                continue;
            }
            try {
                $node = $home->getFirstNodeById((int) $target);
            } catch (\Throwable) {
                continue;
            }
            if ($node !== null && $this->isShared($node)) {
                $watches[$i]['policy_off'] = true;
            }
        }

        return $watches;
    }
}
