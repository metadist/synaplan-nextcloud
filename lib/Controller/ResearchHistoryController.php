<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\ResearchHistory;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Research Chat threads for the signed-in person. File actions do not use this.
 */
class ResearchHistoryController extends Controller
{
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private ResearchHistory $history,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function list(): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->denied();
        }

        return new JSONResponse([
            'success' => true,
            'conversations' => $this->history->list($uid),
        ]);
    }

    #[NoAdminRequired]
    public function open(string $id): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->denied();
        }
        if (!ResearchHistory::validId($id)) {
            return new JSONResponse(['success' => false, 'error' => 'Unknown conversation'], Http::STATUS_NOT_FOUND);
        }

        $chat = $this->history->open($uid, $id);
        if ($chat === null) {
            return new JSONResponse(['success' => false, 'error' => 'Unknown conversation'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(['success' => true, 'conversation' => $chat]);
    }

    #[NoAdminRequired]
    public function save(string $id, array $messages = []): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->denied();
        }
        if (!ResearchHistory::validId($id)) {
            return new JSONResponse(['success' => false, 'error' => 'Unknown conversation'], Http::STATUS_BAD_REQUEST);
        }

        if ($messages === []) {
            $body = json_decode(file_get_contents('php://input') ?: '', true);
            $messages = is_array($body) && is_array($body['messages'] ?? null) ? $body['messages'] : [];
        }
        if ($messages === []) {
            return new JSONResponse(['success' => false, 'error' => 'Messages are required'], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse([
            'success' => true,
            'conversation' => $this->history->save($uid, $id, $messages),
        ]);
    }

    #[NoAdminRequired]
    public function remove(string $id): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->denied();
        }
        if (!ResearchHistory::validId($id)) {
            return new JSONResponse(['success' => false, 'error' => 'Unknown conversation'], Http::STATUS_NOT_FOUND);
        }

        $this->history->remove($uid, $id);

        return new JSONResponse(['success' => true]);
    }

    private function uid(): ?string
    {
        $user = $this->userSession->getUser();

        return $user !== null ? $user->getUID() : null;
    }

    private function denied(): JSONResponse
    {
        return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
    }
}
