<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Http\RespondsToFailures;
use OCA\SynaplanIntegration\Service\LibraryImport;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * New → From Synaplan. Lists the person's files and writes the one they pick
 * into the folder they have open.
 */
class FromSynaplanController extends Controller
{
    use RespondsToFailures;

    public function __construct(
        IRequest $request,
        private LibraryImport $imports,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function list(int $page = 1): JSONResponse
    {
        if ($this->uid() === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new JSONResponse($this->imports->list(max(1, $page)));
        } catch (\Throwable $e) {
            return $this->failed($e, 'Could not list Synaplan files');
        }
    }

    #[NoAdminRequired]
    public function copy(?int $fileId = null, ?string $folder = null, ?string $name = null): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $body = $this->body();
        $fileId ??= isset($body['fileId']) ? (int) $body['fileId'] : 0;
        $folder ??= isset($body['folder']) ? (string) $body['folder'] : '';
        $name ??= isset($body['name']) ? (string) $body['name'] : '';

        try {
            $saved = $this->imports->copy($uid, $fileId, $folder, $name);
        } catch (\InvalidArgumentException) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Could not copy this file.'],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (\Throwable $e) {
            return $this->failed($e, 'Could not copy a Synaplan file');
        }

        return new JSONResponse(['success' => true, ...$saved]);
    }

    private function uid(): ?string
    {
        $user = $this->userSession->getUser();

        return $user instanceof IUser ? $user->getUID() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = $raw !== false && $raw !== '' ? json_decode($raw, true) : [];

        return is_array($decoded) ? $decoded : [];
    }
}
