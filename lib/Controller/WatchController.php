<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Http\Failure;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\WatchTarget;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The watch dialog: what this person can choose, and starting one watch.
 */
class WatchController extends Controller
{
    public function __construct(
        IRequest $request,
        private SynaplanClient $synaplanClient,
        private WatchTarget $targets,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function options(): JSONResponse
    {
        try {
            return new JSONResponse(['success' => true] + $this->synaplanClient->watchOptions());
        } catch (\Throwable $e) {
            return Failure::fromThrowable($e)->toResponse([
                'tasks' => [],
                'languages' => [],
                'shortcuts' => [],
                'folders' => [],
            ]);
        }
    }

    #[NoAdminRequired]
    public function start(
        string $targetKind,
        string $targetId,
        string $preset,
        ?int $taskId = null,
        ?string $groupKey = null,
    ): JSONResponse {
        if (!in_array($targetKind, ['folder', 'file'], true)) {
            return Failure::fromThrowable(new \InvalidArgumentException('Choose a folder or a file.'))->toResponse();
        }
        if (!in_array($preset, ['keep_searchable', 'task'], true)) {
            return Failure::fromThrowable(new \InvalidArgumentException('Choose what should happen.'))->toResponse();
        }
        if ($preset === 'task' && ($taskId === null || $taskId <= 0)) {
            return Failure::fromThrowable(new \InvalidArgumentException('Choose one of your tasks.'))->toResponse();
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
            return Failure::fromThrowable($e)->toResponse();
        }
    }
}
