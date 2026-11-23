<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\BackgroundJob;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Exception\FailureException;
use OCA\SynaplanIntegration\Service\CloudStateService;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Sends the new version of a file that is kept up to date in Synaplan, using
 * that person's stored credential (API key or OAuth access token) and
 * overwrite=1. Synaplan asks for it once, so a passing failure (a held
 * grant whose token cannot be renewed now, Synaplan unreachable or busy) is
 * tried again a few times, later each time.
 */
class ReuploadJob extends QueuedJob
{
    private const MAX_UPLOAD_BYTES = 100 * 1024 * 1024;

    /** Seconds to wait before each retry; after the last, the new version is not sent. */
    public const RETRY_DELAYS = [60, 300, 900, 3600];

    public function __construct(
        ITimeFactory $time,
        private UserAccountService $userAccounts,
        private IRootFolder $rootFolder,
        private CloudStateService $cloudState,
        private SynaplanClient $synaplanClient,
        private LoggerInterface $logger,
        private IJobList $jobs,
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

        $attempt = is_int($argument['attempt'] ?? null) ? $argument['attempt'] : 0;
        $retry = function () use ($uid, $remoteId, $attempt): void {
            $this->retry($uid, $remoteId, $attempt);
        };

        $userKey = $this->userAccounts->credentialFor($uid);
        if ($userKey === '') {
            if ($this->userAccounts->holdsGrant($uid)) {
                $retry();
            }

            return;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            $node = $userFolder->getFirstNodeById((int) $remoteId);
            if (!$node instanceof File || $node->getSize() > self::MAX_UPLOAD_BYTES) {
                return;
            }

            $etag = (string) $node->getEtag();
            $groupKey = $this->resolveSubscribedGroupKey($remoteId, $etag, $userKey, $retry);
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
            if (self::passing($e)) {
                $retry();
            }
        }
    }

    /**
     * No answer, a busy or failing Synaplan; not a refusal of the file or
     * the key.
     */
    private static function passing(\Throwable $e): bool
    {
        if ($e instanceof FailureException) {
            return false;
        }
        $status = (int) $e->getCode();

        return $status === 0 || $status === 429 || $status >= 500;
    }

    private function retry(string $uid, string $remoteId, int $attempt): void
    {
        if ($attempt >= count(self::RETRY_DELAYS)) {
            $this->logger->warning('Gave up re-uploading file {id} for {uid}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'id' => $remoteId,
            ]);

            return;
        }
        $this->jobs->scheduleAfter(
            self::class,
            $this->time->getTime() + self::RETRY_DELAYS[$attempt],
            ['uid' => $uid, 'id' => $remoteId, 'attempt' => $attempt + 1],
        );
    }

    /**
     * @param \Closure(): void $retry
     */
    private function resolveSubscribedGroupKey(string $remoteId, string $etag, string $userKey, \Closure $retry): ?string
    {
        $status = $this->cloudState->status([['id' => $remoteId, 'version' => $etag]], $userKey);
        if (($status['unreachable'] ?? false) === true) {
            $retry();

            return null;
        }
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
