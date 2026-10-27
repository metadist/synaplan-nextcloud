<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Exception\FailureException;
use OCA\SynaplanIntegration\Http\RespondsToFailures;
use OCA\SynaplanIntegration\Service\LanguageService;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * API controller for document AI operations.
 *
 * Provides summarization and translation of Nextcloud files
 * via the Synaplan API.
 */
class ApiController extends Controller
{
    use RespondsToFailures;

    /** Max file size for direct text extraction (5 MB). */
    private const MAX_TEXT_FILE_SIZE = 5 * 1024 * 1024;

    /** Max file size for binary upload to Synaplan (100 MB). */
    private const MAX_UPLOAD_FILE_SIZE = 100 * 1024 * 1024;

    /** MIME types we can extract text from directly (in PHP). */
    private const TEXT_MIME_TYPES = [
        'text/plain',
        'text/markdown',
        'text/csv',
        'text/html',
        'text/xml',
        'text/rtf',
        'application/json',
        'application/xml',
        'application/rtf',
    ];

    /** Binary MIME types that Synaplan can extract text from via Tika. */
    private const BINARY_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.presentation',
    ];

    public function __construct(
        IRequest $request,
        private SynaplanClient $synaplanClient,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private LanguageService $languageService,
        private IConfig $config,
        private IAppManager $appManager,
        private LoggerInterface $logger,
        private ?UserAccountService $userAccounts = null,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Client runtime config for the chat UI.
     *
     * Tells the frontend which output language will be used (so the user knows
     * up front), and whether the Synaplan memory service is reachable so the
     * memory toggle can be shown only when it actually works.
     */
    #[NoAdminRequired]
    public function clientConfig(): JSONResponse
    {
        $language = $this->languageService->resolveLanguage();

        $memoryAllowed = $this->config->getAppValue(Application::APP_ID, 'enable_memories', '1') === '1';
        $memory = ['allowed' => $memoryAllowed, 'available' => false, 'configured' => false];

        if ($memoryAllowed) {
            $serviceState = $this->synaplanClient->checkMemoryService();
            $memory['available'] = $serviceState['available'];
            $memory['configured'] = $serviceState['configured'];
        }

        return new JSONResponse(array_merge([
            'success' => true,
            'version' => $this->appManager->getAppVersion(Application::APP_ID),
            'language' => $language,
            'languageName' => $this->languageService->getLanguageName($language),
            'useInterfaceLanguage' => $this->languageService->useInterfaceLanguage(),
            'memory' => $memory,
        ], $this->userAccounts?->getLinkStatus() ?? []));
    }

    /**
     * Summarize a file.
     *
     * @param int    $fileId         Nextcloud file ID
     * @param string $summaryType    bullet-points|abstractive|extractive
     * @param string $length         short|medium|long
     * @param string $outputLanguage ISO language code (en, de, fr, es, it)
     */
    #[NoAdminRequired]
    public function summarize(
        int $fileId,
        string $summaryType = 'bullet-points',
        string $length = 'medium',
        string $outputLanguage = '',
    ): JSONResponse {
        try {
            if (trim($outputLanguage) === '') {
                $outputLanguage = $this->languageService->resolveLanguage();
            }

            $text = $this->extractFileText($fileId);

            $result = $this->synaplanClient->summarize(
                $text,
                $summaryType,
                $length,
                $outputLanguage,
            );

            return new JSONResponse([
                'success' => true,
                'summary' => $result['summary'] ?? '',
                'metadata' => $result['metadata'] ?? null,
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'Summarization failed');
        }
    }

    /**
     * Translate a file.
     *
     * @param int    $fileId         Nextcloud file ID
     * @param string $targetLanguage ISO language code (en, de, fr, es, it)
     */
    #[NoAdminRequired]
    public function translate(
        int $fileId,
        string $targetLanguage = '',
    ): JSONResponse {
        try {
            if (trim($targetLanguage) === '') {
                $targetLanguage = $this->languageService->resolveLanguage();
            }

            $text = $this->extractFileText($fileId);

            $result = $this->synaplanClient->translate($text, $targetLanguage);

            return new JSONResponse([
                'success' => true,
                'translation' => $result['summary'] ?? '',
                'metadata' => $this->translationMetadata($result, $targetLanguage),
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'Translation failed');
        }
    }

    /**
     * What the browser may show about a translation. Synaplan's own details
     * describe a summary (type, focus, compression) and stay on the server.
     *
     * @param array<string, mixed> $result
     * @return array<string, string>
     */
    private function translationMetadata(array $result, string $targetLanguage): array
    {
        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $out = ['target_language' => $targetLanguage];
        foreach (['model', 'provider'] as $key) {
            if (is_string($metadata[$key] ?? null) && $metadata[$key] !== '') {
                $out[$key] = $metadata[$key];
            }
        }

        return $out;
    }

    /**
     * Upload a Nextcloud file to the Synaplan knowledge base.
     *
     * Sends the file content to Synaplan for text extraction and vectorization.
     *
     * @param int    $fileId   Nextcloud file ID
     * @param string $groupKey Target group key for vectorization
     */
    #[NoAdminRequired]
    public function uploadToKnowledge(int $fileId, string $groupKey = 'DEFAULT', ?bool $keepSynced = null): JSONResponse
    {
        // Parse JSON body for groupKey and keepSynced
        $body = file_get_contents('php://input');
        if ($body !== false && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                if (isset($decoded['groupKey'])) {
                    $groupKey = trim((string) $decoded['groupKey']);
                }
                if ($keepSynced === null && array_key_exists('keepSynced', $decoded)) {
                    $keepSynced = (bool) $decoded['keepSynced'];
                } elseif ($keepSynced === null && array_key_exists('keep_synced', $decoded)) {
                    $keepSynced = (bool) $decoded['keep_synced'];
                }
            }
        }
        $keepSynced = $keepSynced ?? true;

        if ($groupKey === '') {
            $groupKey = 'DEFAULT';
        }

        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                throw new NotFoundException('Not authenticated');
            }

            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $node = $userFolder->getFirstNodeById($fileId);

            if ($node === null || !($node instanceof File)) {
                throw new NotFoundException('File not found');
            }

            if ($node->getSize() > self::MAX_UPLOAD_FILE_SIZE) {
                throw FailureException::fileTooLarge(self::MAX_UPLOAD_FILE_SIZE);
            }

            $content = $node->getContent();
            $filename = $node->getName();
            // Preserve the user-relative source path as provenance (falls back
            // to the basename) so Synaplan can show where this file came from.
            $originalName = $userFolder->getRelativePath($node->getPath()) ?? $filename;

            // CORE-4: key the knowledge entry on the Nextcloud file id + etag and
            // always overwrite, so re-adding (or adding a changed) file updates
            // its entry in place instead of creating a duplicate.
            $result = $this->synaplanClient->uploadFile(
                $filename,
                $content,
                $groupKey,
                'vectorize',
                $originalName,
                (string) $fileId,
                $node->getEtag(),
                true,
                $keepSynced,
            );

            if (($result['success'] ?? false) !== true) {
                // Synaplan's reason (storage quota, rate limit) tells the person what to do.
                $reason = (string) ($result['errors'][0]['error'] ?? ($result['error'] ?? ''));
                throw $reason !== ''
                    ? FailureException::refusedBySynaplan($reason)
                    : FailureException::invalidSynaplanAnswer();
            }
            $fileInfo = $result['files'][0] ?? null;

            return new JSONResponse([
                'success' => true,
                'filename' => $filename,
                'groupKey' => $groupKey,
                'chunksCreated' => $fileInfo['chunks_created'] ?? 0,
                'vectorized' => $fileInfo['vectorized'] ?? false,
                'extractedTextLength' => $fileInfo['extracted_text_length'] ?? 0,
                'overwritten' => $fileInfo['overwritten'] ?? false,
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'Knowledge upload failed');
        }
    }

    /**
     * Knowledge-base status for a Nextcloud file (CORE-4).
     *
     * Reports whether the file is already vectorized, whether it drifted since
     * (the Nextcloud etag no longer matches what was ingested), and the Synaplan
     * file id — so the UI can offer Add vs. Update vs. Remove and a "changed"
     * badge.
     *
     * @param int $fileId Nextcloud file ID
     */
    #[NoAdminRequired]
    public function knowledgeStatus(int $fileId): JSONResponse
    {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                throw new NotFoundException('Not authenticated');
            }

            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $node = $userFolder->getFirstNodeById($fileId);

            if ($node === null || !($node instanceof File)) {
                throw new NotFoundException('File not found');
            }

            $status = $this->synaplanClient->knowledgeStatus($fileId, $node->getEtag());

            return new JSONResponse([
                'success' => true,
                'inKnowledge' => $status['in_knowledge'],
                'stale' => $status['stale'],
            ]);
        } catch (NotFoundException $e) {
            return $this->failed($e, 'Knowledge status failed');
        } catch (\Exception $e) {
            // Status is best-effort — the modal still works if it fails, it just
            // can't pre-detect an existing entry. Never block the user on it.
            $this->logger->info('Knowledge status check failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);

            return new JSONResponse([
                'success' => true,
                'inKnowledge' => false,
                'stale' => false,
                'unavailable' => true,
            ]);
        }
    }

    /**
     * Remove a Nextcloud file from the Synaplan knowledge base (CORE-4).
     *
     * @param int $fileId Nextcloud file ID
     */
    #[NoAdminRequired]
    public function removeFromKnowledge(int $fileId): JSONResponse
    {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                throw new NotFoundException('Not authenticated');
            }

            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $node = $userFolder->getFirstNodeById($fileId);

            $etag = ($node instanceof File) ? $node->getEtag() : null;
            $status = $this->synaplanClient->knowledgeStatus($fileId, $etag);
            $synaplanFileId = $status['synaplan_file_id'];

            // Already absent — treat as success so the UI settles on "removed".
            if (!$status['in_knowledge'] || $synaplanFileId === null) {
                return new JSONResponse(['success' => true, 'removed' => false]);
            }

            $this->synaplanClient->deleteKnowledgeFile((int) $synaplanFileId);

            return new JSONResponse(['success' => true, 'removed' => true]);
        } catch (\Exception $e) {
            return $this->failed($e, 'Remove from knowledge failed');
        }
    }

    /**
     * Get available file groups from Synaplan.
     */
    #[NoAdminRequired]
    public function getGroups(): JSONResponse
    {
        try {
            $result = $this->synaplanClient->getFileGroups();

            return new JSONResponse([
                'success' => true,
                'groups' => $result['groups'] ?? [],
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'Failed to fetch groups', ['groups' => []]);
        }
    }

    /**
     * Get available AI models from Synaplan.
     */
    #[NoAdminRequired]
    public function getModels(): JSONResponse
    {
        try {
            $result = $this->synaplanClient->getModels();
            $models = $result['models'] ?? [];

            $extractModels = static function (array $group): array {
                $out = [];
                foreach ($group as $model) {
                    $out[] = [
                        'id' => $model['id'] ?? 0,
                        'name' => $model['name'] ?? 'Unknown',
                        'service' => $model['service'] ?? '',
                        'providerId' => $model['providerId'] ?? '',
                    ];
                }

                return $out;
            };

            $chatModels = isset($models['CHAT']) && is_array($models['CHAT'])
                ? $extractModels($models['CHAT']) : [];
            $imageModels = isset($models['TEXT2PIC']) && is_array($models['TEXT2PIC'])
                ? $extractModels($models['TEXT2PIC']) : [];
            $videoModels = isset($models['TEXT2VID']) && is_array($models['TEXT2VID'])
                ? $extractModels($models['TEXT2VID']) : [];

            return new JSONResponse([
                'success' => true,
                'models' => $chatModels,
                'imageModels' => $imageModels,
                'videoModels' => $videoModels,
                'capabilities' => [
                    'image' => count($imageModels) > 0,
                    'video' => count($videoModels) > 0,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'Failed to fetch models', [
                'models' => [],
                'imageModels' => [],
                'videoModels' => [],
                'capabilities' => ['image' => false, 'video' => false],
            ]);
        }
    }

    /**
     * Extract text content from a Nextcloud file.
     *
     * For text-based files, reads content directly.
     * For binary documents (PDF, DOCX, etc.), uploads to Synaplan
     * which uses Tika to extract text, then returns the extracted text.
     *
     * @throws NotFoundException if file doesn't exist or user has no access
     * @throws FailureException when the file is too large, empty, of an unsupported type or has no text
     */
    private function extractFileText(int $fileId): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new NotFoundException('Not authenticated');
        }

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        $node = $userFolder->getFirstNodeById($fileId);

        if ($node === null || !($node instanceof File)) {
            throw new NotFoundException('File not found');
        }

        $mimeType = $node->getMimeType();

        // Text files: read directly
        if ($this->isTextMimeType($mimeType)) {
            if ($node->getSize() > self::MAX_TEXT_FILE_SIZE) {
                throw FailureException::fileTooLarge(self::MAX_TEXT_FILE_SIZE);
            }

            $content = $node->getContent();
            if ($content === '') {
                throw FailureException::fileEmpty();
            }

            return $content;
        }

        // Binary documents: upload to Synaplan for Tika extraction, then fetch text
        if ($this->isBinaryDocumentType($mimeType)) {
            if ($node->getSize() > self::MAX_UPLOAD_FILE_SIZE) {
                throw FailureException::fileTooLarge(self::MAX_UPLOAD_FILE_SIZE);
            }

            // Step 1: Upload file — Synaplan extracts text via Tika and stores it
            $uploadResult = $this->synaplanClient->uploadFile(
                $node->getName(),
                $node->getContent(),
                '_nextcloud_temp',
                'extract',
                $userFolder->getRelativePath($node->getPath()) ?? $node->getName(),
            );

            $fileInfo = $uploadResult['files'][0] ?? null;
            if ($fileInfo === null) {
                // Surface the server's actual reason (storage quota, rate limit,
                // unsupported type, …) instead of a generic failure, so the user
                // can act on it (e.g. free space / upgrade the Synaplan plan).
                $detail = (string) ($uploadResult['errors'][0]['error'] ?? ($uploadResult['error'] ?? ''));
                throw $detail !== ''
                    ? FailureException::refusedBySynaplan($detail)
                    : FailureException::invalidSynaplanAnswer();
            }

            $synaplanFileId = $fileInfo['id'] ?? null;
            $extractedLength = $fileInfo['extracted_text_length'] ?? 0;

            if ($synaplanFileId === null || $extractedLength === 0) {
                throw FailureException::noText($node->getName());
            }

            // Step 2: Fetch the extracted text via the content endpoint
            $contentResult = $this->synaplanClient->getFileContent($synaplanFileId);
            $extractedText = $contentResult['extracted_text'] ?? '';

            if ($extractedText === '') {
                throw FailureException::noText($node->getName());
            }

            return $extractedText;
        }

        throw FailureException::unsupportedType($mimeType);
    }

    /**
     * Check if a MIME type represents directly readable text.
     */
    private function isTextMimeType(string $mimeType): bool
    {
        foreach (self::TEXT_MIME_TYPES as $supported) {
            if (str_starts_with($mimeType, $supported)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a MIME type is a binary document Synaplan can extract text from.
     */
    private function isBinaryDocumentType(string $mimeType): bool
    {
        foreach (self::BINARY_MIME_TYPES as $supported) {
            if (str_starts_with($mimeType, $supported)) {
                return true;
            }
        }

        return false;
    }
}
