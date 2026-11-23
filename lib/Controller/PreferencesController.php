<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\CopyDelivery;
use OCA\SynaplanIntegration\Service\ReadScope;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The folder a person wants Synaplan's copies written into.
 */
class PreferencesController extends Controller
{
    public function __construct(
        IRequest $request,
        private IConfig $config,
        private IUserSession $userSession,
        private ReadScope $scope,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_UNAUTHORIZED, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    public function get(): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse([
            'copy_folder' => (string) $this->config->getUserValue($uid, Application::APP_ID, CopyDelivery::COPY_FOLDER, ''),
            'read_scope' => $this->scope->get($uid),
            'read_folder_cap' => $this->scope->cap(),
            'read_scope_ceiling' => $this->scope->ceiling(),
        ]);
    }

    /**
     * Add one folder the person lets Synaplan read.
     *
     * @return JSONResponse<Http::STATUS_BAD_REQUEST, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_CONFLICT, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_UNAUTHORIZED, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    public function allowFolder(string $fileId = '', string $path = ''): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $raw = file_get_contents('php://input');
        $decoded = $raw !== false && $raw !== '' ? json_decode($raw, true) : [];
        if (is_array($decoded)) {
            if ($fileId === '' && isset($decoded['fileId'])) {
                $fileId = (string) $decoded['fileId'];
            }
            if ($path === '' && isset($decoded['path'])) {
                $path = (string) $decoded['path'];
            }
        }
        $reason = $this->scope->allow($uid, $fileId, $path);
        if ($reason === 'ceiling') {
            return new JSONResponse(['success' => false, 'error' => 'ceiling'], Http::STATUS_FORBIDDEN);
        }
        if ($reason === 'cap') {
            return new JSONResponse([
                'success' => false,
                'error' => 'cap',
                'read_scope' => $this->scope->get($uid),
            ], Http::STATUS_CONFLICT);
        }
        if ($reason !== null) {
            return new JSONResponse(['success' => false, 'error' => 'folder'], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(['success' => true, 'read_scope' => $this->scope->get($uid)]);
    }

    /**
     * @return JSONResponse<Http::STATUS_BAD_REQUEST, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_UNAUTHORIZED, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    public function save(?string $copy_folder = null): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        // Only what the body carries changes: a save of the read setting
        // alone keeps the copy folder. Validate everything before anything
        // is stored: a rejected folder must not leave a wider read setting
        // behind.
        $folder = null;
        if ($copy_folder !== null) {
            try {
                $folder = CopyDelivery::cleanFolder($copy_folder);
            } catch (\InvalidArgumentException) {
                return new JSONResponse(
                    ['success' => false, 'error' => 'Could not save that folder.'],
                    Http::STATUS_BAD_REQUEST,
                );
            }
        }
        $scope = $this->request->getParam('read_scope');
        if (is_array($scope)) {
            $mode = (string) ($scope['mode'] ?? '');
            $folders = [];
            foreach (is_array($scope['folders'] ?? null) ? $scope['folders'] : [] as $named) {
                if (!is_array($named)) {
                    continue;
                }
                $folders[] = [
                    'id' => (string) ($named['id'] ?? ''),
                    'path' => (string) ($named['path'] ?? ''),
                ];
            }
            $this->scope->save($uid, $mode, $folders);
        }
        if ($folder !== null) {
            $this->config->setUserValue($uid, Application::APP_ID, CopyDelivery::COPY_FOLDER, $folder);
        }

        return new JSONResponse([
            'success' => true,
            'copy_folder' => (string) $this->config->getUserValue($uid, Application::APP_ID, CopyDelivery::COPY_FOLDER, ''),
            'read_scope' => $this->scope->get($uid),
        ]);
    }

    private function uid(): ?string
    {
        $user = $this->userSession->getUser();

        return $user instanceof IUser ? $user->getUID() : null;
    }
}
