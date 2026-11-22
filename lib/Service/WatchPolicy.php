<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\Share\IManager as IShareManager;

/**
 * The administrator's "Allow watching folders" and "Allow watching shared
 * folders". A folder or a file counts as shared when someone shared it with
 * the person, or when it or a folder above it is shared with anyone else.
 * While a setting is off, no watch can start where it applies, and the
 * watches already there get no files.
 */
class WatchPolicy
{
    public const WATCHES_KEY = 'watch_folders';
    public const SHARED_KEY = 'watch_shared_folders';
    /** Every watch is held: watching folders is turned off. */
    public const OFF_WATCHES = 'watches';
    /** The watch is on a shared target and watching those is turned off. */
    public const OFF_SHARED = 'shared';

    public function __construct(
        private IConfig $config,
        private IShareManager $shares,
        private IRootFolder $rootFolder,
    ) {
    }

    public static function watchingAllowedIn(IConfig $config): bool
    {
        return $config->getAppValue(Application::APP_ID, self::WATCHES_KEY, 'yes') !== 'no';
    }

    public static function sharedAllowedIn(IConfig $config): bool
    {
        return $config->getAppValue(Application::APP_ID, self::SHARED_KEY, 'yes') !== 'no';
    }

    /**
     * How Nextcloud runs background jobs: `ajax` while someone has a page
     * open, `webcron` as often as an outside service calls cron.php, `cron`
     * from the system's scheduler. Unset reads as `ajax`, like Nextcloud.
     *
     * @return 'ajax'|'webcron'|'cron'
     */
    public static function jobsModeIn(IConfig $config): string
    {
        $mode = $config->getAppValue('core', 'backgroundjobs_mode', 'ajax');

        return $mode === 'cron' || $mode === 'webcron' ? $mode : 'ajax';
    }

    /**
     * Watches move on in the every-minute job. In AJAX mode that job runs
     * only while someone has a page of this Nextcloud open.
     */
    public static function jobsOnScheduleIn(IConfig $config): bool
    {
        return self::jobsModeIn($config) !== 'ajax';
    }

    public function jobsOnSchedule(): bool
    {
        return self::jobsOnScheduleIn($this->config);
    }

    public function watchingAllowed(): bool
    {
        return self::watchingAllowedIn($this->config);
    }

    public function sharedAllowed(): bool
    {
        return self::sharedAllowedIn($this->config);
    }

    public function blocks(Node $node): bool
    {
        return $this->heldBy($node) !== null;
    }

    /**
     * Which setting holds watches on this target, if one does.
     *
     * @return self::OFF_*|null
     */
    public function heldBy(Node $node): ?string
    {
        if (!$this->watchingAllowed()) {
            return self::OFF_WATCHES;
        }

        return !$this->sharedAllowed() && $this->isShared($node) ? self::OFF_SHARED : null;
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
     * Mark the watches a setting has stopped, as their owner sees them:
     * `policy_off` names the setting.
     *
     * @param list<array<string, mixed>> $watches rows with a `target_id`
     * @return list<array<string, mixed>>
     */
    public function markStopped(string $uid, array $watches): array
    {
        if (!$this->watchingAllowed()) {
            return array_map(static fn (array $watch): array => ['policy_off' => self::OFF_WATCHES] + $watch, $watches);
        }
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
                $watches[$i]['policy_off'] = self::OFF_SHARED;
            }
        }

        return $watches;
    }
}
