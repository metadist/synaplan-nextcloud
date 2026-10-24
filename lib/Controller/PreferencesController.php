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
        ]);
    }

    /**
     * Add one folder the person lets Synaplan read.
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

    #[NoAdminRequired]
    public function save(?string $copy_folder = null): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        // The framework hands copy_folder over as a named parameter, so the
        // body has to be read either way: read_scope only travels in it.
        $raw = file_get_contents('php://input');
        $decoded = $raw !== false && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($decoded)) {
            $decoded = [];
        }
        if ($copy_folder === null) {
            $copy_folder = isset($decoded['copy_folder']) ? (string) $decoded['copy_folder'] : '';
        }
        if (is_array($decoded['read_scope'] ?? null)) {
            $mode = (string) ($decoded['read_scope']['mode'] ?? '');
            $folders = [];
            foreach (is_array($decoded['read_scope']['folders'] ?? null) ? $decoded['read_scope']['folders'] : [] as $folder) {
                if (!is_array($folder)) {
                    continue;
                }
                $folders[] = [
                    'id' => (string) ($folder['id'] ?? ''),
                    'path' => (string) ($folder['path'] ?? ''),
                ];
            }
            $this->scope->save($uid, $mode, $folders);
        }
        try {
            $folder = CopyDelivery::cleanFolder($copy_folder);
        } catch (\InvalidArgumentException) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Could not save that folder.'],
                Http::STATUS_BAD_REQUEST,
            );
        }
        $this->config->setUserValue($uid, Application::APP_ID, CopyDelivery::COPY_FOLDER, $folder);

        return new JSONResponse([
            'success' => true,
            'copy_folder' => $folder,
            'read_scope' => $this->scope->get($uid),
        ]);
    }

    private function uid(): ?string
    {
        $user = $this->userSession->getUser();

        return $user instanceof IUser ? $user->getUID() : null;
    }
}
