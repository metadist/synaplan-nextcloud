<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Http\RespondsToFailures;
use OCA\SynaplanIntegration\Service\OwnWrites;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\SynaplanFolders;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
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
    use RespondsToFailures;

    public function __construct(
        IRequest $request,
        private SynaplanClient $synaplanClient,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private LoggerInterface $logger,
        private OwnWrites $ownWrites,
        private SynaplanFolders $folders,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Generate an image or video via Synaplan.
     *
     * @return JSONResponse<Http::STATUS_BAD_REQUEST, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_*, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
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
            return $this->failed($e, 'Media generation failed');
        }
    }

    /**
     * Download generated media from Synaplan and save it to the user's Nextcloud files.
     *
     * @return JSONResponse<Http::STATUS_BAD_REQUEST, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_UNAUTHORIZED, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_*, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    public function save(string $mediaUrl = '', string $filename = ''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Not authenticated'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $mediaUrl = trim($mediaUrl);
        $filename = trim($filename);

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
            $targetFolder = $this->folders->kindFolder($userFolder, $user->getUID(), $filename);

            $counter = 1;
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $base = pathinfo($filename, PATHINFO_FILENAME);
            $targetName = $filename;

            while ($targetFolder->nodeExists($targetName)) {
                $targetName = $base . ' (' . $counter . ')' . ($ext !== '' ? '.' . $ext : '');
                $counter++;
            }

            $file = $targetFolder->newFile($targetName);
            $file->putContent($content);
            $this->ownWrites->note($file, time());

            $savedPath = ltrim((string) ($userFolder->getRelativePath($file->getPath()) ?? $targetName), '/');
            $targetFolderPath = dirname($savedPath) === '.' ? '' : dirname($savedPath);

            return new JSONResponse([
                'success' => true,
                'path' => $savedPath,
                'folder' => $targetFolderPath,
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'Media save failed');
        }
    }

    /**
     * Proxy a Synaplan media file through the Nextcloud server.
     *
     * @return Http\Response<Http::STATUS_*, array<string, mixed>>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
            return $this->failed($e, 'Media proxy failed');
        }
    }
}
