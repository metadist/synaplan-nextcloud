<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Http\Failure;
use OCA\SynaplanIntegration\Http\RespondsToFailures;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\WatchTarget;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_*, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    public function options(): JSONResponse
    {
        try {
            return new JSONResponse(['success' => true] + $this->synaplanClient->watchOptions());
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

            return new JSONResponse([
                'success' => true,
                'watch' => $this->synaplanClient->startWatch($body),
            ]);
        } catch (\Throwable $e) {
            return $this->failed($e, 'Starting a watch failed');
        }
    }
}
