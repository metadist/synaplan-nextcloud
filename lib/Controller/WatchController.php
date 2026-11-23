<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Http\Failure;
use OCA\SynaplanIntegration\Http\RespondsToFailures;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\WatchIndex;
use OCA\SynaplanIntegration\Service\WatchPolicy;
use OCA\SynaplanIntegration\Service\WatchReconcile;
use OCA\SynaplanIntegration\Service\WatchTarget;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The watch dialog (what this person can choose, starting one watch) and the
 * owner's controls: the list of their watches, Pause, Resume and Stop.
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
        private PlatformLinkService $links,
        private WatchPolicy $policy,
        private IURLGenerator $urlGenerator,
        private WatchReconcile $reconcile,
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

            return new JSONResponse([
                'success' => true,
                'existing' => $existing,
                'jobsOnSchedule' => $this->policy->jobsOnSchedule(),
            ] + $this->synaplanClient->watchOptions());
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
     * This person's watches that are not stopped, for the Watched folders list.
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_*, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return (new Failure(Http::STATUS_UNAUTHORIZED, Failure::NOT_CONNECTED))->toResponse();
        }
        try {
            $rows = [];
            foreach ($this->links->listWatches($user->getUID()) as $watch) {
                if (is_array($watch) && WatchIndex::parse($watch) !== null) {
                    $rows[] = $watch;
                }
            }
            $rows = $this->policy->markStopped($user->getUID(), $rows);

            return new JSONResponse([
                'success' => true,
                'watches' => array_map(fn (array $watch): array => $this->listed($watch), $rows),
            ]);
        } catch (\Throwable $e) {
            return $this->failed($e, 'Listing the watches failed', ['watches' => []]);
        }
    }

    /**
     * Before Resume: how many files arrived while the watch was paused, and
     * whether to ask about them. A Keep searchable watch takes them without
     * asking; a task watch asks first.
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_*, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    public function waiting(int $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return (new Failure(Http::STATUS_UNAUTHORIZED, Failure::NOT_CONNECTED))->toResponse();
        }
        try {
            $row = $this->index->find($id);
            $waiting = $row !== null && $row['uid'] === $user->getUID() ? $this->reconcile->waiting($row) : 0;
            $preset = '';
            if ($waiting > 0) {
                foreach ($this->links->listWatches($user->getUID()) as $watch) {
                    if (is_array($watch) && is_numeric($watch['id'] ?? null) && (int) $watch['id'] === $id && is_string($watch['preset'] ?? null)) {
                        $preset = $watch['preset'];
                    }
                }
            }

            return new JSONResponse([
                'success' => true,
                'waiting' => $waiting,
                'ask' => $waiting > 0 && $preset !== 'keep_searchable',
            ]);
        } catch (\Throwable $e) {
            return $this->failed($e, 'Counting the files that arrived while paused failed');
        }
    }

    /**
     * Pause or resume one of this person's watches. Resumed without the
     * files that arrived while it was paused, the watch counts only files
     * that change from now on.
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_*, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    public function update(int $id, string $state, bool $handleWaiting = true): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser || !in_array($state, ['paused', 'active'], true)) {
            return (new Failure(Http::STATUS_BAD_REQUEST, Failure::UNEXPECTED))->toResponse();
        }
        try {
            $watch = $this->synaplanClient->updateWatch($id, $state);
            $now = $this->time->getTime();
            $this->note(function () use ($user, $watch, $now, $state, $handleWaiting, $id): void {
                if ($state === 'active' && !$handleWaiting) {
                    $this->index->restartFrom($id, $now);
                }
                $this->index->update($user->getUID(), $watch, $now);
            });

            return new JSONResponse(['success' => true, 'watch' => $this->listed($watch)]);
        } catch (\Throwable $e) {
            return $this->failed($e, $state === 'paused' ? 'Pausing a watch failed' : 'Resuming a watch failed');
        }
    }

    /**
     * Stop one of this person's watches.
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_*, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    public function stop(int $id): JSONResponse
    {
        try {
            $this->synaplanClient->stopWatch($id);
            $this->note(fn () => $this->index->stop($id));

            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->failed($e, 'Stopping a watch failed');
        }
    }

    /**
     * What the list and the sidebar show of a watch.
     *
     * @param array<mixed> $watch a watch as Synaplan answers it
     * @return array{id: int, kind: string, targetId: string, name: string, state: string, sentence: string, lastRunAt: ?int, policyOff: ?string, openUrl: string}
     */
    private function listed(array $watch): array
    {
        $targetId = is_scalar($watch['target_id'] ?? null) ? (string) $watch['target_id'] : '';

        return [
            'id' => is_numeric($watch['id'] ?? null) ? (int) $watch['id'] : 0,
            'kind' => ($watch['target_kind'] ?? '') === 'file' ? 'file' : 'folder',
            'targetId' => $targetId,
            'name' => is_string($watch['name'] ?? null) ? $watch['name'] : '',
            'state' => is_string($watch['state'] ?? null) ? $watch['state'] : '',
            'sentence' => is_string($watch['sentence'] ?? null) ? $watch['sentence'] : '',
            'lastRunAt' => is_int($watch['last_run_at'] ?? null) ? $watch['last_run_at'] : null,
            'policyOff' => in_array($watch['policy_off'] ?? null, [WatchPolicy::OFF_WATCHES, WatchPolicy::OFF_SHARED], true) ? $watch['policy_off'] : null,
            'openUrl' => ctype_digit($targetId) ? $this->urlGenerator->getAbsoluteURL('/index.php/f/' . $targetId) : '',
        ];
    }

    /**
     * Synaplan already did what the person asked; the index catches up on
     * the next sync when noting it here fails.
     *
     * @param callable(): void $change
     */
    private function note(callable $change): void
    {
        try {
            $change();
        } catch (\Throwable $e) {
            $this->logger->warning('Could not note a watch change here: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
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
