<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\BackgroundJob\DeliveryRetryJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\AlreadyExistsException;
use OCP\Files\EntityTooLargeException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\IRootFolder;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * The app side of a copy: claim it, download it, write it into the person's
 * files and report what happened. Synaplan also reads the same report from
 * the notification answer.
 */
class CopyDelivery
{
    public const COPY_FOLDER = 'copy_folder';

    /** Remember a file this app wrote, so a later copy may replace only that. */
    private const OWN_WRITE_TTL = 600;

    /** Synaplan's cap for a copy; it refuses larger files before sending. */
    private const MAX_BYTES = 100 * 1024 * 1024;

    /** How long Synaplan keeps a copy's ticket and download path. */
    private const TICKET_TTL = 300;

    /** `name.ext` and `name (2).ext` up to `name (20).ext`. */
    private const MAX_SAME_NAME = 20;

    private const KIND_FOLDERS = [
        'docx' => 'Documents', 'doc' => 'Documents', 'pptx' => 'Documents',
        'ppt' => 'Documents', 'xlsx' => 'Documents', 'xls' => 'Documents',
        'csv' => 'Documents', 'pdf' => 'Documents', 'txt' => 'Documents',
        'md' => 'Documents', 'odt' => 'Documents', 'rtf' => 'Documents',
        'mp3' => 'Audio', 'wav' => 'Audio', 'ogg' => 'Audio',
        'm4a' => 'Audio', 'flac' => 'Audio', 'aac' => 'Audio',
        'ics' => 'Calendar',
        'png' => 'Images', 'jpg' => 'Images', 'jpeg' => 'Images',
        'gif' => 'Images', 'webp' => 'Images', 'svg' => 'Images',
        'mp4' => 'Video', 'webm' => 'Video', 'mov' => 'Video', 'mkv' => 'Video',
    ];

    public function __construct(
        private PlatformLinkService $links,
        private IRootFolder $rootFolder,
        private IUserManager $users,
        private IConfig $config,
        private ICacheFactory $cacheFactory,
        private IURLGenerator $urls,
        private IManager $notifications,
        private LoggerInterface $logger,
        private IJobList $jobs,
        private ITimeFactory $time,
        private OwnWrites $ownWrites,
    ) {
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $id, string $uid): array
    {
        // Synaplan waits 30 seconds for the answer, then takes the result
        // call instead; a slow download must not stop when it hangs up.
        ignore_user_abort(true);

        return $this->deliver($id, $uid, null);
    }

    /**
     * The background try after a failed claim. Past the ticket's life the
     * claim can only fail, so Synaplan's sweep reports the copy instead.
     */
    public function retry(string $id, string $uid, int $since): void
    {
        if ($this->time->getTime() - $since > self::TICKET_TTL) {
            $this->logger->info('A Synaplan copy expired before it could be claimed again', [
                'app' => Application::APP_ID,
            ]);

            return;
        }
        $this->deliver($id, $uid, $since);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function deliver(string $id, string $uid, ?int $retriedSince): array
    {
        try {
            $claim = $this->links->claimDelivery($id, $uid);
        } catch (\Throwable $e) {
            // Synaplan is unreachable or the person's credential failed. A
            // 202 promises a result call, so try once more in the background.
            $this->logger->warning('Could not claim a Synaplan copy: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
            if ($retriedSince === null) {
                $this->jobs->add(DeliveryRetryJob::class, ['id' => $id, 'uid' => $uid, 'since' => $this->time->getTime()]);
            }

            return ['status' => 202, 'body' => ['state' => 'pending']];
        }
        if (isset($claim['status_code']) && (int) $claim['status_code'] !== 200) {
            // Another worker claimed it, or it expired. Leave it pending.
            return ['status' => 202, 'body' => ['state' => 'pending']];
        }

        $result = (string) ($claim['external_id'] ?? '') === $uid
            ? $this->write($id, $claim)
            : ['status' => 'failed', 'error_code' => 'not_found'];
        try {
            $this->links->reportDelivery($id, $uid, $result);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not report a Synaplan copy: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
        }

        return ['status' => 200, 'body' => $result];
    }

    /**
     * A folder a person typed. Empty means the Synaplan folder. `..` is refused.
     */
    public static function cleanFolder(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');
        if ($folder === '') {
            return '';
        }
        foreach (explode('/', $folder) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('That folder cannot be used.');
            }
        }

        return $folder;
    }

    /**
     * @param array<string, mixed> $claim
     * @return array<string, mixed>
     */
    private function write(string $id, array $claim): array
    {
        $uid = (string) ($claim['external_id'] ?? '');
        if ($uid === '' || $this->users->get($uid) === null) {
            return ['status' => 'failed', 'error_code' => 'not_found'];
        }
        if ((int) ($claim['size'] ?? 0) > self::MAX_BYTES) {
            return ['status' => 'failed', 'error_code' => 'too_large'];
        }
        $bytes = $this->links->downloadDelivery($id, (string) ($claim['download_path'] ?? ''), $uid);
        if ($bytes === null) {
            return ['status' => 'failed', 'error_code' => 'unreachable'];
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return ['status' => 'failed', 'error_code' => 'too_large'];
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            $name = $this->fileName((string) ($claim['name'] ?? 'file'));
            $target = is_array($claim['target'] ?? null) ? $claim['target'] : [];
            $folder = $this->folder($userFolder, $uid, $name, $target);
            $free = $folder->getFreeSpace();
            if ($free >= 0 && $free < strlen($bytes)) {
                throw new NotEnoughSpaceException('The copy does not fit in the person\'s storage.');
            }
            $replace = ($claim['on_conflict'] ?? '') === 'replace_own';
            $file = $this->place($folder, $uid, $name, $bytes, $replace);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not write a Synaplan copy for {uid}: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);

            return ['status' => 'failed', 'error_code' => self::failureCode($e)];
        }

        $relative = $userFolder->getRelativePath($file->getPath()) ?? $file->getName();
        $relative = ltrim((string) $relative, '/');
        $this->rememberOwnWrite($uid, (int) $file->getId());
        $openUrl = $this->urls->linkToRouteAbsolute('files.View.showFile', ['fileid' => $file->getId()]);
        $this->notify(
            $uid,
            $file->getName(),
            dirname($relative) === '.' ? '' : dirname($relative),
            (int) $file->getId(),
            $openUrl,
        );

        return [
            'status' => 'delivered',
            'remote_path' => '/' . $relative,
            'remote_id' => (string) $file->getId(),
            'open_url' => $openUrl,
        ];
    }

    /**
     * @param array<string, mixed> $target
     */
    private function folder(Folder $userFolder, string $uid, string $name, array $target): Folder
    {
        $kind = (string) ($target['kind'] ?? 'kind_folder');
        $ref = (string) ($target['ref'] ?? '');
        if (($kind === 'folder_id' || $kind === 'next_to') && $ref !== '') {
            $nodes = $userFolder->getById((int) $ref);
            $node = $nodes[0] ?? null;
            if ($node instanceof Folder) {
                return $node;
            }
            if ($node instanceof File) {
                return $node->getParent();
            }
            throw new NotFoundException('The folder Synaplan named is not in this account.');
        }
        try {
            $root = self::cleanFolder((string) $this->config->getUserValue($uid, Application::APP_ID, self::COPY_FOLDER, ''));
        } catch (\InvalidArgumentException) {
            $root = '';
        }
        $root = $root !== '' ? $root : 'Synaplan';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $sub = self::KIND_FOLDERS[$ext] ?? '';

        return $this->ensure($userFolder, trim($root . '/' . $sub, '/'));
    }

    private function place(Folder $folder, string $uid, string $name, string $bytes, bool $replaceOwn): File
    {
        if ($replaceOwn && $folder->nodeExists($name)) {
            $existing = $folder->get($name);
            if ($existing instanceof File && $this->wrote($uid, (int) $existing->getId())) {
                $existing->putContent($bytes);
                $this->ownWrites->note($existing, time());

                return $existing;
            }
        }
        $target = $name;
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $n = 2;
        while ($folder->nodeExists($target)) {
            if ($n > self::MAX_SAME_NAME) {
                throw new AlreadyExistsException('Twenty files with this name already exist.');
            }
            $target = $stem . ' (' . $n . ')' . ($ext !== '' ? '.' . $ext : '');
            $n++;
        }
        $file = $folder->newFile($target);
        $file->putContent($bytes);
        $this->ownWrites->note($file, time());

        return $file;
    }

    /**
     * The failure code Synaplan shows the person (`03` §6).
     */
    private static function failureCode(\Throwable $e): string
    {
        return match (true) {
            $e instanceof NotFoundException => 'not_found',
            $e instanceof NotEnoughSpaceException => 'quota_exceeded',
            $e instanceof NotPermittedException, $e instanceof ForbiddenException => 'forbidden',
            $e instanceof AlreadyExistsException => 'conflict',
            $e instanceof EntityTooLargeException => 'too_large',
            default => 'unreachable',
        };
    }

    private function ensure(Folder $root, string $path): Folder
    {
        $current = $root;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            if ($current->nodeExists($segment)) {
                $node = $current->get($segment);
                if (!$node instanceof Folder) {
                    throw new NotFoundException('A file is where a folder should be.');
                }
                $current = $node;
            } else {
                $current = $current->newFolder($segment);
            }
        }

        return $current;
    }

    private function fileName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));

        return $base !== '' && $base !== '.' && $base !== '..' ? $base : 'file';
    }

    private function rememberOwnWrite(string $uid, int $fileId): void
    {
        $this->cacheFactory->createDistributed(Application::APP_ID)
            ->set($this->ownKey($uid, $fileId), '1', self::OWN_WRITE_TTL);
    }

    private function wrote(string $uid, int $fileId): bool
    {
        return $this->cacheFactory->createDistributed(Application::APP_ID)
            ->get($this->ownKey($uid, $fileId)) !== null;
    }

    private function ownKey(string $uid, int $fileId): string
    {
        return 'own_' . hash('sha256', $uid) . '_' . $fileId;
    }

    private function notify(string $uid, string $name, string $folder, int $fileId, string $link): void
    {
        try {
            $notification = $this->notifications->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($uid)
                ->setDateTime(new \DateTime())
                ->setObject('file', (string) $fileId)
                ->setSubject('copied', ['name' => $name, 'folder' => $folder, 'link' => $link]);
            $this->notifications->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->info('Could not notify {uid} about a Synaplan copy: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
