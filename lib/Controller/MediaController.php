<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for media generation (image/video) and saving to Nextcloud.
 */
class MediaController extends Controller
{
    private const SYNAPLAN_FOLDER = 'Synaplan';

    public function __construct(
        IRequest $request,
        private SynaplanClient $synaplanClient,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Generate an image or video via Synaplan.
     *
     * @NoAdminRequired
     */
    public function generate(): JSONResponse
    {
        $body = file_get_contents('php://input');
        $data = $body !== false ? json_decode($body, true) : [];

        if (!is_array($data)) {
            $data = [];
        }

        $prompt = trim((string) ($data['prompt'] ?? ''));
        $type = trim((string) ($data['type'] ?? ''));
        $modelId = isset($data['modelId']) ? (int) $data['modelId'] : null;

        if ($prompt === '') {
            return new JSONResponse(
                ['success' => false, 'error' => 'Prompt is required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        if (!in_array($type, ['image', 'video'], true)) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Type must be "image" or "video"'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        try {
            $result = $this->synaplanClient->generateMedia($prompt, $type, $modelId);

            return new JSONResponse([
                'success' => true,
                'file' => $result['file'] ?? null,
                'provider' => $result['provider'] ?? 'unknown',
                'model' => $result['model'] ?? 'unknown',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Media generation failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);

            return new JSONResponse(
                ['success' => false, 'error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * Download generated media from Synaplan and save it to the user's Nextcloud files.
     *
     * @NoAdminRequired
     */
    public function save(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Not authenticated'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $body = file_get_contents('php://input');
        $data = $body !== false ? json_decode($body, true) : [];

        if (!is_array($data)) {
            $data = [];
        }

        $mediaUrl = trim((string) ($data['mediaUrl'] ?? ''));
        $filename = trim((string) ($data['filename'] ?? ''));

        if ($mediaUrl === '' || $filename === '') {
            return new JSONResponse(
                ['success' => false, 'error' => 'mediaUrl and filename are required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $filename = basename($filename);

        try {
            $content = $this->synaplanClient->downloadFile($mediaUrl);

            $userFolder = $this->rootFolder->getUserFolder($user->getUID());

            if (!$userFolder->nodeExists(self::SYNAPLAN_FOLDER)) {
                $userFolder->newFolder(self::SYNAPLAN_FOLDER);
            }

            $synaplanFolder = $userFolder->get(self::SYNAPLAN_FOLDER);

            $targetName = $filename;
            $counter = 1;
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $base = pathinfo($filename, PATHINFO_FILENAME);

            while ($synaplanFolder->nodeExists($targetName)) {
                $targetName = $base . ' (' . $counter . ')' . ($ext !== '' ? '.' . $ext : '');
                $counter++;
            }

            $file = $synaplanFolder->newFile($targetName);
            $file->putContent($content);

            $savedPath = self::SYNAPLAN_FOLDER . '/' . $targetName;

            return new JSONResponse([
                'success' => true,
                'path' => $savedPath,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Media save failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);

            return new JSONResponse(
                ['success' => false, 'error' => 'Failed to save file: ' . $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * Proxy a Synaplan media file through the Nextcloud server.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function proxy(string $url = ''): Http\Response
    {
        if ($url === '') {
            return new JSONResponse(
                ['error' => 'url parameter is required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        try {
            $content = $this->synaplanClient->downloadFile($url);

            $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
            $mimeType = match ($ext) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'mp4' => 'video/mp4',
                'webm' => 'video/webm',
                default => 'application/octet-stream',
            };

            $filename = basename($url);

            return new DataDownloadResponse($content, $filename, $mimeType);
        } catch (\Exception $e) {
            $this->logger->error('Media proxy failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);

            return new JSONResponse(
                ['error' => 'Failed to proxy file'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
