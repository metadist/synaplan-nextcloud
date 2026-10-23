<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\CopyDelivery;
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
        ]);
    }

    #[NoAdminRequired]
    public function save(?string $copy_folder = null): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if ($copy_folder === null) {
            $raw = file_get_contents('php://input');
            $decoded = $raw !== false && $raw !== '' ? json_decode($raw, true) : [];
            $copy_folder = is_array($decoded) && isset($decoded['copy_folder']) ? (string) $decoded['copy_folder'] : '';
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

        return new JSONResponse(['success' => true, 'copy_folder' => $folder]);
    }

    private function uid(): ?string
    {
        $user = $this->userSession->getUser();

        return $user instanceof IUser ? $user->getUID() : null;
    }
}
