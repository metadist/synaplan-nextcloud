<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Hands one file to one watch, as the watch's owner sees it: the bytes of
 * the current version, its etag as the version, its name and path. A file
 * this app wrote and nobody changed since goes as an own write, without its
 * bytes; one over Synaplan's 100 MiB limit is not sent.
 *
 * @phpstan-import-type WatchRow from WatchIndex
 */
class WatchSender
{
    public const SENT = 'sent';
    public const DROP = 'drop';
    public const RETRY = 'retry';
    /** Synaplan no longer runs the watch: the person's index is out of date. */
    public const NOT_RUNNING = 'not_running';
    public const MAX_BYTES = 100 * 1024 * 1024;
    private const PATH_MAX_BYTES = 255;

    public function __construct(
        private PlatformLinkService $links,
        private IRootFolder $rootFolder,
        private OwnWrites $ownWrites,
        private LoggerInterface $logger,
        private WatchPolicy $policy,
    ) {
    }

    /**
     * @param WatchRow $watch
     */
    public function file(array $watch, int $fileId): string
    {
        $node = $this->ownerNode($watch['uid'], $fileId);
        if (!$node instanceof File || !self::belongs($watch, $node)) {
            return self::DROP;
        }
        if ($this->policy->blocks($watch['kind'] === 'file' ? $node : $node->getParent())) {
            return self::DROP;
        }
        try {
            $fields = [
                'id' => (string) $fileId,
                'version' => self::version($node),
                'name' => $node->getName(),
                'path' => $this->ownerPath($watch['uid'], $node),
            ];
            if ($this->ownWrites->isUnchanged($fileId, (int) $node->getMTime())) {
                return $this->send($watch, $fields + ['own_write' => '1'], null);
            }
            if ($node->getSize() > self::MAX_BYTES) {
                $this->logger->info('Not sending {name} to watch {watch}: it is larger than 100 MiB', [
                    'app' => Application::APP_ID,
                    'name' => $node->getName(),
                    'watch' => $watch['id'],
                ]);

                return self::DROP;
            }
            $body = $node->fopen('r');
            if ($body === false) {
                return self::RETRY;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not read file {id} for watch {watch}: {message}', [
                'app' => Application::APP_ID,
                'id' => $fileId,
                'watch' => $watch['id'],
                'message' => $e->getMessage(),
            ]);

            return self::RETRY;
        }

        return $this->send($watch, $fields, [
            'bytes' => $body,
            'name' => $node->getName(),
            'mime' => $node->getMimetype() !== '' ? $node->getMimetype() : 'application/octet-stream',
        ]);
    }

    /**
     * Tell the watch that its own folder or file was deleted or restored.
     *
     * @param WatchRow $watch
     */
    public function targetEvent(array $watch, string $event, int $now): string
    {
        return $this->send($watch, [
            'id' => (string) $watch['target'],
            'version' => $event . '-' . $now,
            'event' => $event,
        ], null);
    }

    /**
     * The version a watch knows a file by: its etag.
     */
    public static function version(Node $node): string
    {
        $etag = substr((string) $node->getEtag(), 0, 255);

        return $etag !== '' ? $etag : (string) $node->getMTime();
    }

    /**
     * A folder watch takes the files directly in its folder; a file watch
     * only its own file.
     *
     * @param WatchRow $watch
     */
    public static function belongs(array $watch, Node $node): bool
    {
        if ($watch['kind'] === 'file') {
            return (int) $node->getId() === $watch['target'];
        }
        try {
            return (int) $node->getParent()->getId() === $watch['target'];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The target as the watch's owner sees it, or null when it is gone or
     * no longer readable for them.
     */
    public function ownerNode(string $uid, int $id): File|Folder|null
    {
        try {
            $node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($id);
        } catch (\Throwable) {
            return null;
        }
        if (!$node instanceof File && !$node instanceof Folder) {
            return null;
        }

        return $node->isReadable() ? $node : null;
    }

    /**
     * @param WatchRow $watch
     * @param array<string, string> $fields
     * @param array{bytes: resource|string, name: string, mime: string}|null $file
     */
    private function send(array $watch, array $fields, ?array $file): string
    {
        try {
            $answer = $this->links->sendWatchFile($watch['uid'], $watch['id'], $fields, $file);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not send a file to Synaplan watch {watch}: {message}', [
                'app' => Application::APP_ID,
                'watch' => $watch['id'],
                'message' => $e->getMessage(),
            ]);

            return self::RETRY;
        } finally {
            if ($file !== null && is_resource($file['bytes'])) {
                fclose($file['bytes']);
            }
        }
        $status = is_int($answer['status_code'] ?? null) ? $answer['status_code'] : 0;
        if ($status >= 200 && $status < 300) {
            return self::SENT;
        }
        $error = is_string($answer['error'] ?? null) ? $answer['error'] : '';
        if ($status === 404 || ($status === 409 && $error !== 'storage_quota') || $status === 403) {
            return self::NOT_RUNNING;
        }
        if ($status === 401 || $status === 409 || $status === 429 || $status >= 500 || $status === 0) {
            return self::RETRY;
        }
        $this->logger->warning('Synaplan refused a file for watch {watch} ({status}): {message}', [
            'app' => Application::APP_ID,
            'watch' => $watch['id'],
            'status' => $status,
            'message' => is_string($answer['message'] ?? null) ? $answer['message'] : '',
        ]);

        return self::DROP;
    }

    private function ownerPath(string $uid, Node $node): string
    {
        try {
            $relative = $this->rootFolder->getUserFolder($uid)->getRelativePath($node->getPath());
        } catch (\Throwable) {
            return '';
        }
        $path = is_string($relative) ? ltrim($relative, '/') : '';

        return strlen($path) <= self::PATH_MAX_BYTES ? $path : '';
    }
}
