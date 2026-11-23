<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\Files\Folder;
use OCP\Config\IUserConfig;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * What this person lets Synaplan read. The app decides; Synaplan only keeps
 * a copy so a run can be refused before it asks.
 */
class ReadScope
{
    public const PREF = 'read_scope';
    public const PENDING = 'read_scope_pending';
    public const CAP = 'read_folder_cap';
    public const DEFAULT_CAP = 20;

    /** The widest choice the administrator lets people make. */
    public const CEILING = 'read_scope_ceiling';

    public const MODE_WATCHED = 'watched';
    public const MODE_ALLOWED = 'allowed';
    public const MODE_ALL = 'all';

    /** Narrowest first. */
    public const MODES = [self::MODE_WATCHED, self::MODE_ALLOWED, self::MODE_ALL];

    private const LOCK_WAIT_SECONDS = 10;

    /** @var array<string, ?string> */
    private array $resolved = [];

    public function __construct(
        private IConfig $config,
        private PlatformLinkService $links,
        private WatchIndex $watches,
        private IRootFolder $rootFolder,
        private IUserConfig $userConfig,
        private IAppConfig $appConfig,
        private ILockingProvider $locks,
    ) {
    }

    /**
     * The administrator's ceiling; "folders people allow" until one is set.
     */
    public static function ceilingIn(IConfig $config): string
    {
        $ceiling = $config->getAppValue(Application::APP_ID, self::CEILING, self::MODE_ALLOWED);

        return in_array($ceiling, self::MODES, true) ? $ceiling : self::MODE_ALLOWED;
    }

    public function ceiling(): string
    {
        return self::ceilingIn($this->config);
    }

    /**
     * Set the ceiling. Lowering it narrows the saved choice of everyone above
     * it, so raising it later widens nobody without their saying so. Synaplan
     * learns the narrowed choices from the hourly job. Returns how many
     * people were narrowed.
     *
     * No personal change runs meanwhile: one that began earlier is saved
     * before the sweep reads it, one that begins later sees the new ceiling.
     *
     * @throws LockedException when personal changes keep running
     */
    public function setCeiling(string $ceiling): int
    {
        if (!in_array($ceiling, self::MODES, true)) {
            throw new \InvalidArgumentException('Unknown read ceiling.');
        }
        $this->acquire(self::ceilingLock(), ILockingProvider::LOCK_EXCLUSIVE);
        try {
            $this->config->setAppValue(Application::APP_ID, self::CEILING, $ceiling);
            $narrowed = 0;
            foreach ($this->userConfig->getValuesByUsers(Application::APP_ID, self::PREF) as $uid => $raw) {
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                if (!is_array($decoded) || !self::wider((string) ($decoded['mode'] ?? ''), $ceiling)) {
                    continue;
                }
                $decoded['mode'] = $ceiling;
                $this->config->setUserValue((string) $uid, Application::APP_ID, self::PREF, (string) json_encode($decoded, JSON_UNESCAPED_SLASHES));
                $this->config->setUserValue((string) $uid, Application::APP_ID, self::PENDING, '1');
                $narrowed++;
            }
            $this->userConfig->clearCacheAll();

            return $narrowed;
        } finally {
            $this->locks->releaseLock(self::ceilingLock(), ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    /**
     * Run a change of the person's choice and its report to Synaplan alone:
     * after any ceiling change under way, after any other change of theirs,
     * and on the values saved now rather than those this request read
     * earlier.
     *
     * @template T
     * @param callable(): T $change
     * @return T
     * @throws LockedException when a ceiling change or another change of theirs keeps running
     */
    private function changing(string $uid, callable $change): mixed
    {
        $person = Application::APP_ID . '/read-scope/' . $uid;
        $this->acquire(self::ceilingLock(), ILockingProvider::LOCK_SHARED);
        try {
            $this->acquire($person, ILockingProvider::LOCK_EXCLUSIVE);
            try {
                $this->appConfig->clearCache();
                $this->userConfig->clearCache($uid);
                $this->resolved = [];

                return $change();
            } finally {
                $this->locks->releaseLock($person, ILockingProvider::LOCK_EXCLUSIVE);
            }
        } finally {
            $this->locks->releaseLock(self::ceilingLock(), ILockingProvider::LOCK_SHARED);
        }
    }

    private static function ceilingLock(): string
    {
        return Application::APP_ID . '/read-scope-ceiling';
    }

    /**
     * @param ILockingProvider::LOCK_* $type
     * @throws LockedException
     */
    private function acquire(string $lock, int $type): void
    {
        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        while (true) {
            try {
                $this->locks->acquireLock($lock, $type);

                return;
            } catch (LockedException $e) {
                if (microtime(true) >= $deadline) {
                    throw $e;
                }
                usleep(100_000);
            }
        }
    }

    /**
     * True when $mode lets Synaplan read more than $than.
     */
    public static function wider(string $mode, string $than): bool
    {
        $rank = array_search($mode, self::MODES, true);
        $limit = array_search($than, self::MODES, true);

        return $rank !== false && $limit !== false && $rank > $limit;
    }

    /**
     * @return array{mode: string, folders: list<array{id: string, path: string}>}
     */
    public function get(string $uid): array
    {
        $raw = $this->config->getUserValue($uid, Application::APP_ID, self::PREF, '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $mode = is_array($decoded) ? (string) ($decoded['mode'] ?? '') : '';
        if (!in_array($mode, self::MODES, true)) {
            $mode = self::MODE_WATCHED;
        }
        $ceiling = $this->ceiling();
        if (self::wider($mode, $ceiling)) {
            $mode = $ceiling;
        }
        $folders = [];
        foreach (is_array($decoded['folders'] ?? null) ? $decoded['folders'] : [] as $folder) {
            if (!is_array($folder)) {
                continue;
            }
            $path = self::clean((string) ($folder['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $folders[] = ['id' => (string) ($folder['id'] ?? ''), 'path' => $path];
        }

        return ['mode' => $mode, 'folders' => $folders];
    }

    public function cap(): int
    {
        $cap = (int) $this->config->getAppValue(Application::APP_ID, self::CAP, (string) self::DEFAULT_CAP);

        return max(1, min(500, $cap));
    }

    /**
     * A folder the person named. `ceiling` when the administrator lets
     * Synaplan read only watched folders, `cap` when the administrator's
     * limit is full, `folder` when the name cannot be used.
     */
    public function allow(string $uid, string $id, string $path): ?string
    {
        return $this->changing($uid, fn (): ?string => $this->allowNow($uid, $id, $path));
    }

    private function allowNow(string $uid, string $id, string $path): ?string
    {
        if ($this->ceiling() === self::MODE_WATCHED) {
            return 'ceiling';
        }
        $path = self::clean($path);
        if ($path === '') {
            return 'folder';
        }
        $scope = $this->get($uid);
        foreach ($scope['folders'] as $folder) {
            if ($folder['path'] === $path || $this->currentPath($uid, $folder) === $path) {
                // Already named, but the folder menu promises this folder may
                // be read now: under "Only what I watch" that promise takes
                // the mode switch too, or nothing changes.
                if ($scope['mode'] === self::MODE_WATCHED) {
                    $scope['mode'] = self::MODE_ALLOWED;
                    $this->store($uid, $scope);
                }

                return null;
            }
        }
        if (count($scope['folders']) >= $this->cap()) {
            return 'cap';
        }
        $scope['folders'][] = ['id' => $id, 'path' => $path];
        if ($scope['mode'] === self::MODE_WATCHED) {
            $scope['mode'] = self::MODE_ALLOWED;
        }
        $this->store($uid, $scope);

        return null;
    }

    /**
     * @param list<array{id: string, path: string}> $folders
     */
    public function save(string $uid, string $mode, array $folders): void
    {
        $this->changing($uid, fn () => $this->saveNow($uid, $mode, $folders));
    }

    /**
     * @param list<array{id: string, path: string}> $folders
     */
    private function saveNow(string $uid, string $mode, array $folders): void
    {
        if (!in_array($mode, self::MODES, true)) {
            $mode = self::MODE_WATCHED;
        }
        $ceiling = $this->ceiling();
        if (self::wider($mode, $ceiling)) {
            $mode = $ceiling;
        }
        $kept = [];
        foreach ($folders as $folder) {
            $path = self::clean($folder['path']);
            if ($path === '') {
                continue;
            }
            $kept[] = ['id' => $folder['id'], 'path' => $path];
            if (count($kept) >= $this->cap()) {
                break;
            }
        }
        $this->store($uid, ['mode' => $mode, 'folders' => $kept]);
    }

    /**
     * The person's own rule, not the copy Synaplan stored. A person who
     * chose nothing allowed nothing.
     *
     * "Only what I watch" allows the folders the person's own watches look
     * at (that folder, not its sub-folders: a watch looks at that folder
     * only), and never falls back to the named folders. "Also folders I
     * allow" adds the named folders to those.
     */
    public function mayRead(string $uid, string $folder): bool
    {
        $scope = $this->get($uid);
        if ($scope['mode'] === self::MODE_ALL) {
            return true;
        }
        $folder = self::clean($folder);
        if ($folder !== '' && in_array($folder, $this->watchedFolders($uid), true)) {
            return true;
        }
        if ($scope['mode'] !== self::MODE_ALLOWED) {
            return false;
        }
        foreach ($scope['folders'] as $allowed) {
            $path = $this->currentPath($uid, $allowed);
            if ($path === null) {
                continue;
            }
            if ($folder === $path || str_starts_with($folder . '/', $path . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the person sees the folders their watches look at.
     *
     * @return list<string>
     */
    private function watchedFolders(string $uid): array
    {
        $paths = [];
        try {
            $home = $this->rootFolder->getUserFolder($uid);
            foreach ($this->watches->rowsOf($uid) as $watch) {
                if ($watch['kind'] !== 'folder') {
                    continue;
                }
                $node = $home->getFirstNodeById($watch['target']);
                $relative = $node instanceof Folder ? $home->getRelativePath($node->getPath()) : null;
                $path = is_string($relative) ? self::clean($relative) : '';
                if ($path !== '') {
                    $paths[] = $path;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $paths;
    }

    public static function clean(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return '';
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return '';
            }
        }

        return $path;
    }

    /**
     * Send the saved setting again after a report failed. True when Synaplan
     * holds it now.
     */
    public function republish(string $uid): bool
    {
        return $this->changing($uid, fn (): bool => $this->republishNow($uid));
    }

    private function republishNow(string $uid): bool
    {
        $scope = $this->get($uid);
        if (!$this->report($uid, $scope)) {
            return false;
        }
        $this->config->deleteUserValue($uid, Application::APP_ID, self::PENDING);

        return true;
    }

    /**
     * Bring the saved paths up to date after folders moved. Synaplan refuses
     * a run, and names folders to its planner, from the paths in its copy,
     * so a moved allowed folder is reported again. True when a path changed.
     * A folder that cannot be found (deleted, in the trash) keeps its path.
     */
    public function follow(string $uid): bool
    {
        return $this->changing($uid, fn (): bool => $this->followNow($uid));
    }

    private function followNow(string $uid): bool
    {
        $scope = $this->get($uid);
        if ($scope['folders'] === []) {
            return false;
        }
        $moved = false;
        foreach ($scope['folders'] as $i => $folder) {
            if ($folder['id'] === '') {
                continue;
            }
            $path = $this->currentPath($uid, $folder);
            if ($path !== null && $path !== $folder['path']) {
                $scope['folders'][$i]['path'] = $path;
                $moved = true;
            }
        }
        if ($moved) {
            $this->store($uid, $scope);
        }

        return $moved;
    }

    /**
     * Where an allowed folder is now. The person allowed a folder, not a
     * name: a folder moved or renamed keeps its permission, and a new folder
     * at the old name does not get it. An entry saved without an id is
     * matched by its path.
     *
     * @param array{id: string, path: string} $allowed
     */
    private function currentPath(string $uid, array $allowed): ?string
    {
        if ($allowed['id'] === '') {
            return $allowed['path'];
        }
        $key = $uid . "\0" . $allowed['id'];
        if (!array_key_exists($key, $this->resolved)) {
            $this->resolved[$key] = null;
            try {
                $userFolder = $this->rootFolder->getUserFolder($uid);
                foreach ($userFolder->getById((int) $allowed['id']) as $node) {
                    if ($node instanceof Folder) {
                        $path = self::clean((string) $userFolder->getRelativePath($node->getPath()));
                        $this->resolved[$key] = $path !== '' ? $path : null;
                        break;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $this->resolved[$key];
    }

    /**
     * The id of each folder as this Nextcloud knows it, whatever id the
     * request carried. A path that names no folder keeps an empty id.
     *
     * @param array{mode: string, folders: list<array{id: string, path: string}>} $scope
     * @return array{mode: string, folders: list<array{id: string, path: string}>}
     */
    private function withIds(string $uid, array $scope): array
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
        } catch (\Throwable) {
            return $scope;
        }
        foreach ($scope['folders'] as $i => $folder) {
            try {
                $node = $userFolder->get($folder['path']);
                $id = $node instanceof Folder ? (string) $node->getId() : '';
            } catch (\Throwable) {
                $id = '';
            }
            $scope['folders'][$i]['id'] = $id;
        }

        return $scope;
    }

    /**
     * @param array{mode: string, folders: list<array{id: string, path: string}>} $scope
     */
    private function store(string $uid, array $scope): void
    {
        $scope = $this->withIds($uid, $scope);
        $this->resolved = [];
        $this->config->setUserValue(
            $uid,
            Application::APP_ID,
            self::PREF,
            (string) json_encode($scope, JSON_UNESCAPED_SLASHES),
        );
        if ($this->report($uid, $scope)) {
            $this->config->deleteUserValue($uid, Application::APP_ID, self::PENDING);
        } else {
            // The setting here is what a read checks, but Synaplan refuses a
            // run from its copy before it asks. Keep the debt where the hourly
            // job finds it instead of dropping it.
            $this->config->setUserValue($uid, Application::APP_ID, self::PENDING, '1');
        }
    }

    /**
     * The named folders stay here under the other choices, so switching back
     * restores them, but Synaplan opens a read of any folder in its copy
     * without looking at the mode.
     *
     * @param array{mode: string, folders: list<array{id: string, path: string}>} $scope
     */
    private function report(string $uid, array $scope): bool
    {
        $folders = $scope['mode'] === self::MODE_ALLOWED ? $scope['folders'] : [];
        try {
            return $this->links->reportReadScope($uid, $scope['mode'], $folders);
        } catch (\Throwable) {
            return false;
        }
    }
}
