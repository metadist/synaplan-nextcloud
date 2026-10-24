<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\IConfig;

/**
 * What this person lets Synaplan read. The app decides; Synaplan only keeps
 * a copy so a run can be refused before it asks.
 */
class ReadScope
{
    public const PREF = 'read_scope';
    public const CAP = 'read_folder_cap';
    public const DEFAULT_CAP = 20;

    public const MODE_WATCHED = 'watched';
    public const MODE_ALLOWED = 'allowed';
    public const MODE_ALL = 'all';

    public function __construct(
        private IConfig $config,
        private PlatformLinkService $links,
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
            if ($folder['path'] === $path) {
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
     * chose nothing allowed nothing. Watched folders arrive with watches.
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
            $path = $allowed['path'];
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
     * @param array{mode: string, folders: list<array{id: string, path: string}>} $scope
     */
    private function store(string $uid, array $scope): void
    {
        $this->config->setUserValue(
            $uid,
            Application::APP_ID,
            self::PREF,
            (string) json_encode($scope, JSON_UNESCAPED_SLASHES),
        );
        try {
            $this->links->reportReadScope($uid, $scope['mode'], $scope['folders']);
        } catch (\Throwable) {
            // The setting here is what a read checks. Synaplan's copy can catch up.
        }
    }
}
