<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The app side of a folder read: claim it, check the person's own setting,
 * list the folder, send what was asked for, and report.
 *
 * A read that is still going after {@see self::BUDGET_SECONDS} continues in
 * {@see \OCA\SynaplanIntegration\BackgroundJob\PickupJob}.
 */
class FolderRead
{
    public const BUDGET_SECONDS = 20;

    public function __construct(
        private PlatformLinkService $links,
        private ReadScope $scope,
        private IRootFolder $rootFolder,
        private IUserManager $users,
        private ICacheFactory $cacheFactory,
        private IJobList $jobs,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(string $id, int $budgetSeconds = self::BUDGET_SECONDS): void
    {
        try {
            $this->start($id, $budgetSeconds);
        } catch (\Throwable $e) {
            $this->logger->warning('A folder read could not be answered: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Uploads left over from a read that did not finish in the request.
     */
    public function finish(string $id): void
    {
        $pending = $this->cache()->get('pickup-' . $id);
        $this->cache()->remove('pickup-' . $id);
        if (!is_array($pending)) {
            return;
        }
        $uid = (string) ($pending['uid'] ?? '');
        $left = is_array($pending['left'] ?? null) ? $pending['left'] : [];
        $sent = (int) ($pending['sent'] ?? 0);
        $listed = (int) ($pending['listed'] ?? 0);
        $skipped = is_array($pending['skipped'] ?? null) ? $pending['skipped'] : [];
        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
        } catch (\Throwable $e) {
            $this->report($id, 'failed', $listed, $sent, $skipped, 'unreachable');

            return;
        }
        foreach ($left as $item) {
            if (!is_array($item)) {
                continue;
            }
            $outcome = $this->uploadOne($id, $userFolder, $item);
            if ($outcome === null) {
                $sent++;
            } else {
                $skipped[] = $outcome;
            }
        }
        $this->close($id, $listed, $sent, $skipped);
    }

    private function start(string $id, int $budgetSeconds): void
    {
        $claim = $this->links->claimPickup($id);
        if (isset($claim['status_code']) && (int) $claim['status_code'] !== 200) {
            return;
        }
        $uid = $this->uid((string) ($claim['external_id'] ?? ''));
        if ($uid === null) {
            $this->report($id, 'failed', 0, 0, [], 'not_found');

            return;
        }
        $selector = is_array($claim['selector'] ?? null) ? $claim['selector'] : [];
        $userFolder = $this->rootFolder->getUserFolder($uid);
        $folder = $this->folder($userFolder, $selector);
        if (!$folder instanceof Folder) {
            $this->report($id, 'failed', 0, 0, [], 'not_found');

            return;
        }
        $relative = $this->relativeFolder($userFolder, $folder);
        if (!$this->scope->mayRead($uid, $relative)) {
            $this->report($id, 'failed', 0, 0, [], 'outside_scope');

            return;
        }

        $since = self::since($selector['changed_since'] ?? null);
        $recursive = ($selector['recursive'] ?? false) === true;
        $files = $this->filesIn($userFolder, $folder, $recursive, $since);
        $items = [];
        foreach ($files as $file) {
            $items[] = [
                'id' => $file['id'],
                'version' => $file['version'],
                'name' => $file['name'],
                'folder' => $file['folder'],
                'size' => $file['size'],
                'mime' => $file['mime'],
            ];
        }
        $listing = $this->links->submitPickupListing($id, $items);
        if (isset($listing['status_code']) && (int) $listing['status_code'] !== 200) {
            $this->report($id, 'failed', count($items), 0, [], 'unreachable');

            return;
        }

        $byId = [];
        foreach ($files as $file) {
            $byId[$file['id']] = $file;
        }
        $skipped = [];
        foreach (is_array($listing['skip'] ?? null) ? $listing['skip'] : [] as $skip) {
            if (!is_array($skip)) {
                continue;
            }
            $remoteId = (string) ($skip['id'] ?? '');
            if (!isset($byId[$remoteId])) {
                continue;
            }
            $skipped[] = [
                'id' => $remoteId,
                'name' => $byId[$remoteId]['name'],
                'reason' => (string) ($skip['reason'] ?? ''),
            ];
        }
        $upload = [];
        foreach (is_array($listing['upload'] ?? null) ? $listing['upload'] : [] as $remoteId) {
            if (is_string($remoteId) && isset($byId[$remoteId])) {
                $upload[] = ['id' => $remoteId, 'version' => $byId[$remoteId]['version']];
            }
        }

        $started = microtime(true);
        $sent = 0;
        $left = [];
        foreach ($upload as $item) {
            if (microtime(true) - $started > $budgetSeconds) {
                $left[] = $item;
                continue;
            }
            $outcome = $this->uploadOne($id, $userFolder, $item);
            if ($outcome === null) {
                $sent++;
            } else {
                $skipped[] = $outcome;
            }
        }
        if ($left !== []) {
            $this->cache()->set('pickup-' . $id, [
                'uid' => $uid,
                'left' => $left,
                'sent' => $sent,
                'listed' => count($items),
                'skipped' => $skipped,
            ], 900);
            $this->jobs->add(\OCA\SynaplanIntegration\BackgroundJob\PickupJob::class, ['id' => $id]);

            return;
        }
        $this->close($id, count($items), $sent, $skipped);
    }

    /**
     * @param array{id: string, version: string} $item
     * @return array{id: string, name: string, reason: string}|null null when the file was stored
     */
    private function uploadOne(string $pickupId, Folder $userFolder, array $item): ?array
    {
        $nodes = $userFolder->getById((int) $item['id']);
        $node = $nodes[0] ?? null;
        if (!$node instanceof File) {
            return ['id' => $item['id'], 'name' => $item['id'], 'reason' => 'unreadable'];
        }
        try {
            $bytes = $node->getContent();
        } catch (\Throwable) {
            return ['id' => $item['id'], 'name' => $node->getName(), 'reason' => 'unreadable'];
        }
        $stored = $this->links->uploadPickupFile(
            $pickupId,
            $item['id'],
            $item['version'],
            $bytes,
            $node->getName(),
            $node->getMimeType(),
        );
        if (($stored['status_code'] ?? 0) === 200) {
            return null;
        }

        return [
            'id' => $item['id'],
            'name' => $node->getName(),
            'reason' => (string) ($stored['error'] ?? 'unreadable'),
        ];
    }

    /**
     * @param list<array{id: string, name: string, reason: string}> $skipped
     */
    private function close(string $id, int $listed, int $sent, array $skipped): void
    {
        $missing = array_filter(
            $skipped,
            static fn (array $row): bool => $row['reason'] !== 'type_not_read',
        );
        $this->report($id, $missing === [] ? 'done' : 'partial', $listed, $sent, $skipped, null);
    }

    /**
     * @param list<array{id: string, name: string, reason: string}> $skipped
     */
    private function report(string $id, string $status, int $listed, int $sent, array $skipped, ?string $error): void
    {
        $body = [
            'status' => $status,
            'listed' => $listed,
            'sent' => $sent,
            'skipped' => $skipped,
        ];
        if ($error !== null) {
            $body['error_code'] = $error;
        }
        try {
            $this->links->reportPickupResult($id, $body);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not report a folder read: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function uid(string $externalId): ?string
    {
        if ($externalId !== '' && $this->users->get($externalId) !== null) {
            return $externalId;
        }
        $suffix = str_contains($externalId, ':') ? substr($externalId, (int) strrpos($externalId, ':') + 1) : '';

        return $suffix !== '' && $this->users->get($suffix) !== null ? $suffix : null;
    }

    /**
     * @param array<string, mixed> $selector
     */
    private function folder(Folder $userFolder, array $selector): ?Folder
    {
        $id = is_string($selector['folder_id'] ?? null) ? $selector['folder_id'] : '';
        if ($id !== '' && ctype_digit($id)) {
            $nodes = $userFolder->getById((int) $id);
            $node = $nodes[0] ?? null;

            return $node instanceof Folder ? $node : null;
        }
        $path = ReadScope::clean((string) ($selector['folder_path'] ?? ''));
        if ($path === '') {
            return $userFolder;
        }
        try {
            $node = $userFolder->get($path);
        } catch (NotFoundException) {
            return null;
        }

        return $node instanceof Folder ? $node : null;
    }

    private function relativeFolder(Folder $userFolder, Folder $folder): string
    {
        if ($folder->getPath() === $userFolder->getPath()) {
            return '';
        }
        $relative = $userFolder->getRelativePath($folder->getPath());

        return ReadScope::clean(is_string($relative) ? $relative : $folder->getName());
    }

    /**
     * @return list<array{id: string, version: string, name: string, folder: string, size: int, mime: string}>
     */
    private function filesIn(Folder $userFolder, Folder $folder, bool $recursive, int $since): array
    {
        $found = [];
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof Folder) {
                if ($recursive) {
                    $found = array_merge($found, $this->filesIn($userFolder, $node, true, $since));
                }
                continue;
            }
            if (!$node instanceof File || $node->getMTime() < $since) {
                continue;
            }
            $relative = $userFolder->getRelativePath($node->getPath());
            $relative = is_string($relative) ? trim($relative, '/') : $node->getName();
            $parent = str_contains($relative, '/') ? dirname($relative) : '';
            $found[] = [
                'id' => (string) $node->getId(),
                'version' => (string) $node->getEtag(),
                'name' => $node->getName(),
                'folder' => $parent === '.' ? '' : $parent,
                'size' => $node->getSize(),
                'mime' => $node->getMimeType(),
            ];
        }

        return $found;
    }

    /**
     * "7d", "12h" or "2w" as a cut-off. Anything else means no cut-off.
     */
    public static function since(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^(\d+)([hdw])$/', $value, $match) !== 1) {
            return 0;
        }

        return time() - (int) $match[1] * ['h' => 3600, 'd' => 86400, 'w' => 604800][$match[2]];
    }

    private function cache(): ICache
    {
        return $this->cacheFactory->createDistributed(Application::APP_ID);
    }
}
