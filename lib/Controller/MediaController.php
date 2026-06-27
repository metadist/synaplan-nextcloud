<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
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

    /**
     * Map a file extension to a kind sub-folder under {@see self::SYNAPLAN_FOLDER}
     * so saved artifacts are organised (Documents/Audio/Calendar/Images/Video).
     * Unknown extensions land directly in the Synaplan root folder.
     */
    private const KIND_FOLDERS = [
        // Documents
        'docx' => 'Documents', 'doc' => 'Documents', 'pptx' => 'Documents',
        'ppt' => 'Documents', 'xlsx' => 'Documents', 'xls' => 'Documents',
        'csv' => 'Documents', 'pdf' => 'Documents', 'txt' => 'Documents',
        'md' => 'Documents', 'odt' => 'Documents', 'rtf' => 'Documents',
        // Audio
        'mp3' => 'Audio', 'wav' => 'Audio', 'ogg' => 'Audio',
        'm4a' => 'Audio', 'flac' => 'Audio', 'aac' => 'Audio',
        // Calendar
        'ics' => 'Calendar',
        // Images
        'png' => 'Images', 'jpg' => 'Images', 'jpeg' => 'Images',
        'gif' => 'Images', 'webp' => 'Images', 'svg' => 'Images',
        // Video
        'mp4' => 'Video', 'webm' => 'Video', 'mov' => 'Video', 'mkv' => 'Video',
    ];

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

            // Organise saved artifacts into Synaplan/<Kind> sub-folders by type
            // (Documents/Audio/Calendar/Images/Video); unknown types go to the
            // Synaplan root folder.
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $kindFolder = self::KIND_FOLDERS[$ext] ?? null;
            $targetFolderPath = self::SYNAPLAN_FOLDER
                . ($kindFolder !== null ? '/' . $kindFolder : '');

            $targetFolder = $this->ensureFolder($userFolder, $targetFolderPath);

            $counter = 1;
            $base = pathinfo($filename, PATHINFO_FILENAME);
            $targetName = $filename;

            while ($targetFolder->nodeExists($targetName)) {
                $targetName = $base . ' (' . $counter . ')' . ($ext !== '' ? '.' . $ext : '');
                $counter++;
            }

            $file = $targetFolder->newFile($targetName);
            $file->putContent($content);

            $savedPath = $targetFolderPath . '/' . $targetName;

            return new JSONResponse([
                'success' => true,
                'path' => $savedPath,
                'folder' => $targetFolderPath,
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

    /**
     * Ensure a (possibly nested, slash-separated) folder path exists under the
     * given user folder, creating each missing segment, and return the leaf
     * folder node.
     */
    private function ensureFolder(Folder $userFolder, string $path): Folder
    {
        $current = $userFolder;

        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($current->nodeExists($segment)) {
                $node = $current->get($segment);
                if (!($node instanceof Folder)) {
                    throw new \RuntimeException('Path segment is not a folder: ' . $segment);
                }
                $current = $node;
            } else {
                $current = $current->newFolder($segment);
            }
        }

        return $current;
    }
}
