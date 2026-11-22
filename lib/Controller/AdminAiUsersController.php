<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Exception\FailureException;
use OCA\SynaplanIntegration\Service\AdminAiUsersService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin control panel: which Nextcloud users have activated AI, their Synaplan
 * account + usage, and the ability to deactivate a user.
 *
 * All methods are admin-only (no @NoAdminRequired), matching SettingsController.
 */
class AdminAiUsersController extends Controller
{
    public function __construct(
        IRequest $request,
        private AdminAiUsersService $aiUsers,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>
     */
    public function list(): JSONResponse
    {
        return new JSONResponse([
            'success' => true,
            'users' => $this->aiUsers->listActivatedUsers(),
        ]);
    }

    /**
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>
     */
    public function usage(string $uid): JSONResponse
    {
        return new JSONResponse($this->aiUsers->usage($uid));
    }

    /**
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_*, array<string, mixed>, array{}>
     */
    public function deactivate(string $uid): JSONResponse
    {
        try {
            $this->aiUsers->deactivate($uid);
        } catch (FailureException $e) {
            return $e->failure->toResponse();
        }

        return new JSONResponse(['success' => true]);
    }
}
