<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;

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

    public const MODE_WATCHED = 'watched';
    public const MODE_ALLOWED = 'allowed';
    public const MODE_ALL = 'all';

    /** @var array<string, ?string> */
    private array $resolved = [];

    public function __construct(
        private IConfig $config,
        private PlatformLinkService $links,
        private IRootFolder $rootFolder,
    ) {
    }

    /**
     * @return array{mode: string, folders: list<array{id: string, path: string}>}
     */
    public function get(string $uid): array
    {
        $raw = $this->config->getUserValue($uid, Application::APP_ID, self::PREF, '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $mode = is_array($decoded) ? (string) ($decoded['mode'] ?? '') : '';
        if (!in_array($mode, [self::MODE_WATCHED, self::MODE_ALLOWED, self::MODE_ALL], true)) {
            $mode = self::MODE_WATCHED;
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
     * A folder the person named. `cap` when the administrator's limit is
     * full, `folder` when the name cannot be used.
     */
    public function allow(string $uid, string $id, string $path): ?string
    {
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
        if (!in_array($mode, [self::MODE_WATCHED, self::MODE_ALLOWED, self::MODE_ALL], true)) {
            $mode = self::MODE_WATCHED;
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
     * "Only what I watch" names the folders a watch brings. This app has no
     * watches yet (they are a later step), so that choice allows nothing for
     * now: it fails closed and never falls back to the named folders. Naming
     * a folder — here or through the folder's own menu — is what opens a
     * folder in the meantime.
     */
    public function mayRead(string $uid, string $folder): bool
    {
        $scope = $this->get($uid);
        if ($scope['mode'] === self::MODE_ALL) {
            return true;
        }
        if ($scope['mode'] !== self::MODE_ALLOWED) {
            return false;
        }
        $folder = self::clean($folder);
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
        $scope = $this->get($uid);
        if ($scope['folders'] === []) {
            return false;
        }
        $this->resolved = [];
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
     * @param array{mode: string, folders: list<array{id: string, path: string}>} $scope
     */
    private function report(string $uid, array $scope): bool
    {
        try {
            return $this->links->reportReadScope($uid, $scope['mode'], $scope['folders']);
        } catch (\Throwable) {
            return false;
        }
    }
}
