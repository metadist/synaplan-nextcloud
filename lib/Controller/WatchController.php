<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Http\Failure;
use OCA\SynaplanIntegration\Http\RespondsToFailures;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\WatchIndex;
use OCA\SynaplanIntegration\Service\WatchTarget;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The watch dialog: what this person can choose, and starting one watch.
 */
class WatchController extends Controller
{
    use RespondsToFailures;

    public function __construct(
        IRequest $request,
        private SynaplanClient $synaplanClient,
        private WatchTarget $targets,
        private LoggerInterface $logger,
        private WatchIndex $index,
        private IUserSession $userSession,
        private ITimeFactory $time,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @param string|null $targetId a folder: how many files are already in it
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_*, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    public function options(?string $targetId = null): JSONResponse
    {
        try {
            $existing = $targetId !== null && $targetId !== '' ? $this->targets->existingFiles($targetId) : 0;

            return new JSONResponse(['success' => true, 'existing' => $existing] + $this->synaplanClient->watchOptions());
        } catch (\Throwable $e) {
            return $this->failed($e, 'Loading the watch options failed', [
                'tasks' => [],
                'languages' => [],
                'shortcuts' => [],
                'folders' => [],
            ]);
        }
    }

    /**
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_*, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    public function start(
        string $targetKind,
        string $targetId,
        string $preset,
        ?int $taskId = null,
        ?string $groupKey = null,
        bool $backfill = false,
    ): JSONResponse {
        // The dialog only sends choices it offered; anything else is a bad request.
        if (!in_array($targetKind, ['folder', 'file'], true)
            || !in_array($preset, ['keep_searchable', 'task'], true)
            || ($preset === 'task' && ($taskId === null || $taskId <= 0))) {
            return (new Failure(Http::STATUS_BAD_REQUEST, Failure::UNEXPECTED))->toResponse();
        }

        try {
            $body = $this->targets->resolve($targetKind, $targetId);
            $body['preset'] = $preset;
            if ($preset === 'task') {
                $body['task_id'] = $taskId;
            }
            if ($preset === 'keep_searchable' && $groupKey !== null && trim($groupKey) !== '') {
                $body['group_key'] = trim($groupKey);
            }

            $watch = $this->synaplanClient->startWatch($body);
            $this->remember($watch, $targetKind === 'folder' && $backfill);

            return new JSONResponse([
                'success' => true,
                'watch' => $watch,
            ]);
        } catch (\Throwable $e) {
            return $this->failed($e, 'Starting a watch failed');
        }
    }

    /**
     * The new watch is fed from the next cron run, before Synaplan's notice
     * or the next sync lists it. Files already in the folder go only when
     * the person asked for them.
     *
     * @param array<mixed> $watch
     */
    private function remember(array $watch, bool $backfill): void
    {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return;
        }
        try {
            $this->index->remember($user->getUID(), $watch, $backfill ? 0 : $this->time->getTime());
        } catch (\Throwable $e) {
            $this->logger->warning('Could not note the new watch here: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
