<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\BackgroundJob;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\CloudStateService;
use OCA\SynaplanIntegration\Service\KeyStorage;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Sends the new version of a file that is kept up to date in Synaplan, using
 * that person's stored key and overwrite=1.
 */
class ReuploadJob extends QueuedJob
{
    private const MAX_UPLOAD_BYTES = 100 * 1024 * 1024;

    public function __construct(
        ITimeFactory $time,
        private KeyStorage $keys,
        private IRootFolder $rootFolder,
        private CloudStateService $cloudState,
        private SynaplanClient $synaplanClient,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    protected function run(mixed $argument): void
    {
        if (!is_array($argument)) {
            return;
        }
        $uid = is_string($argument['uid'] ?? null) ? trim($argument['uid']) : '';
        $remoteId = is_string($argument['id'] ?? null) ? trim($argument['id']) : '';
        if ($uid === '' || !ctype_digit($remoteId)) {
            return;
        }

        $userKey = $this->keys->getUserKey($uid);
        if ($userKey === null || $userKey === '') {
            return;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            $node = $userFolder->getFirstNodeById((int) $remoteId);
            if (!$node instanceof File || $node->getSize() > self::MAX_UPLOAD_BYTES) {
                return;
            }

            $etag = (string) $node->getEtag();
            $groupKey = $this->resolveSubscribedGroupKey($remoteId, $etag, $userKey);
            if ($groupKey === null) {
                return;
            }
            $relative = $userFolder->getRelativePath($node->getPath()) ?? $node->getName();

            $this->synaplanClient->uploadFile(
                $node->getName(),
                $node->getContent(),
                $groupKey,
                'vectorize',
                ltrim($relative, '/'),
                $remoteId,
                $etag,
                true,
                true,
                $userKey,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Could not re-upload file {id} for {uid}: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'id' => $remoteId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function resolveSubscribedGroupKey(string $remoteId, string $etag, string $userKey): ?string
    {
        $status = $this->cloudState->status([['id' => $remoteId, 'version' => $etag]], $userKey);
        $item = $status['items'][0] ?? null;
        if (!is_array($item)
            || ($item['kept_up_to_date'] ?? false) !== true
            || ($item['state'] ?? 'none') === 'none') {
            return null;
        }
        $folder = $item['knowledge_folder'] ?? null;
        if (is_array($folder) && is_string($folder['key'] ?? null) && $folder['key'] !== '') {
            return $folder['key'];
        }

        return 'DEFAULT';
    }
}
