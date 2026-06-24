<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Http\SseProxyResponse;
use OCA\SynaplanIntegration\Service\LanguageService;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for document chat and research chat.
 *
 * Provides Q&A endpoints for Nextcloud files using
 * Synaplan's AI summarization engine.
 */
class ChatController extends Controller
{
    /** Max text to include as context (50 KB). */
    private const MAX_CONTEXT_SIZE = 50 * 1024;

    public function __construct(
        IRequest $request,
        private SynaplanClient $synaplanClient,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private LanguageService $languageService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Ask a question, optionally with file context or RAG group.
     *
     * @NoAdminRequired
     *
     * @param string      $message     User's question
     * @param int|null    $fileId      Nextcloud file ID for document context
     * @param string|null $groupKey    RAG group key for knowledge base context
     * @param int|null    $modelId     Specific model ID to use
     * @param bool        $useMemories Whether to enrich the answer with the user's memories
     */
    public function chat(string $message = '', ?int $fileId = null, ?string $groupKey = null, ?int $modelId = null, bool $useMemories = false): JSONResponse
    {
        // Parse JSON body as fallback
        if ($message === '') {
            $body = file_get_contents('php://input');
            if ($body !== false && $body !== '') {
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    $message = trim((string) ($decoded['message'] ?? ''));
                    $fileId = isset($decoded['fileId']) ? (int) $decoded['fileId'] : $fileId;
                    $groupKey = isset($decoded['groupKey']) ? trim((string) $decoded['groupKey']) : $groupKey;
                    $modelId = isset($decoded['modelId']) ? (int) $decoded['modelId'] : $modelId;
                    $useMemories = isset($decoded['useMemories']) ? (bool) $decoded['useMemories'] : $useMemories;
                }
            }
        }

        if ($message === '') {
            return new JSONResponse(
                ['success' => false, 'error' => 'Message is required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        try {
            $context = '';

            if ($fileId !== null) {
                $fileInfo = $this->getFileInfo($fileId);
                $context = $fileInfo['content'];
            }

            if ($useMemories) {
                $memoryContext = $this->synaplanClient->searchMemoryContext($message);
                if ($memoryContext !== '') {
                    $context = "What you remember about the user:\n" . $memoryContext
                        . ($context !== '' ? "\n\n" . $context : '');
                }
            }

            $language = $this->languageService->resolveLanguage();
            $result = $this->synaplanClient->ask($message, $context, $language, $groupKey, $modelId);

            return new JSONResponse([
                'success' => true,
                'response' => $result['summary'] ?? '',
                'deepLink' => $this->synaplanClient->getBaseUrl(),
            ]);
        } catch (NotFoundException $e) {
            return new JSONResponse(
                ['success' => false, 'error' => 'File not found'],
                Http::STATUS_NOT_FOUND,
            );
        } catch (\Exception $e) {
            $this->logger->error('Chat failed: {message}', [
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
     * Ask a question and stream the answer token-by-token via SSE.
     *
     * Proxies Synaplan's OpenAI-compatible `/v1/chat/completions` streaming
     * endpoint. RAG (group) and file context are resolved here, exactly like
     * {@see self::chat()}, then relayed to the browser as it arrives.
     *
     * @NoAdminRequired
     *
     * @param string      $message     User's question
     * @param int|null    $fileId      Nextcloud file ID for document context
     * @param string|null $groupKey    RAG group key for knowledge base context
     * @param string|null $model       Provider model id/name (null = user default)
     * @param bool        $useMemories Whether to enrich the answer with the user's memories
     */
    public function chatStream(string $message = '', ?int $fileId = null, ?string $groupKey = null, ?string $model = null, bool $useMemories = false): Response
    {
        if ($message === '') {
            $body = file_get_contents('php://input');
            if ($body !== false && $body !== '') {
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    $message = trim((string) ($decoded['message'] ?? ''));
                    $fileId = isset($decoded['fileId']) ? (int) $decoded['fileId'] : $fileId;
                    $groupKey = isset($decoded['groupKey']) ? trim((string) $decoded['groupKey']) : $groupKey;
                    $model = isset($decoded['model']) ? trim((string) $decoded['model']) : $model;
                    $useMemories = isset($decoded['useMemories']) ? (bool) $decoded['useMemories'] : $useMemories;
                }
            }
        }

        if ($message === '') {
            return new JSONResponse(
                ['success' => false, 'error' => 'Message is required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        try {
            $context = '';

            if ($fileId !== null) {
                $context = $this->getFileInfo($fileId)['content'];
            }

            if ($groupKey !== null && $groupKey !== '') {
                $ragContext = $this->synaplanClient->searchRagContext($message, $groupKey);
                if ($ragContext !== '') {
                    $context = $ragContext . ($context !== '' ? "\n\n" . $context : '');
                }
            }

            $memoryContext = '';
            if ($useMemories) {
                $memoryContext = $this->synaplanClient->searchMemoryContext($message);
            }

            $userContent = $context !== ''
                ? "Based on the following context, answer the user's question.\n\n"
                    . "--- CONTEXT ---\n" . $context . "\n--- END CONTEXT ---\n\n"
                    . 'Question: ' . $message
                : $message;

            $messages = [
                ['role' => 'system', 'content' => $this->buildSystemPrompt($memoryContext)],
                ['role' => 'user', 'content' => $userContent],
            ];

            $payload = ['messages' => $messages, 'stream' => true];
            if ($model !== null && $model !== '') {
                $payload['model'] = $model;
            }

            return new SseProxyResponse(
                $this->synaplanClient->getBaseUrl() . '/v1/chat/completions',
                $this->synaplanClient->getApiKey(),
                (string) json_encode($payload),
                $this->logger,
            );
        } catch (NotFoundException $e) {
            return new JSONResponse(
                ['success' => false, 'error' => 'File not found'],
                Http::STATUS_NOT_FOUND,
            );
        } catch (\Exception $e) {
            $this->logger->error('Chat stream failed: {message}', [
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
     * Build the system prompt that pins the answer language (admin default or
     * the user's interface language) and, optionally, injects the user's
     * long-term memories so the assistant can personalise its reply.
     */
    private function buildSystemPrompt(string $memoryContext = ''): string
    {
        $languageName = $this->languageService->getLanguageName(
            $this->languageService->resolveLanguage()
        );

        $parts = [
            'You are Synaplan AI, a helpful assistant integrated into Nextcloud.',
            sprintf(
                'Unless the user explicitly asks for a different language, always answer in %s.',
                $languageName,
            ),
        ];

        if (trim($memoryContext) !== '') {
            $parts[] = "Here is what you remember about the user. Use it when relevant, "
                . "but do not mention that you are reading from memory:\n" . $memoryContext;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Get file name and text content for chat context.
     *
     * @return array{name: string, content: string}
     * @throws NotFoundException
     */
    private function getFileInfo(int $fileId): array
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

        $content = '';
        $mimeType = $node->getMimeType();

        // Only extract text from text-based files
        if (str_starts_with($mimeType, 'text/')
            || $mimeType === 'application/json'
            || $mimeType === 'application/xml'
        ) {
            $content = $node->getContent();
            if (strlen($content) > self::MAX_CONTEXT_SIZE) {
                $content = mb_substr($content, 0, self::MAX_CONTEXT_SIZE) . "\n\n[truncated]";
            }
        }

        return [
            'name' => $node->getName(),
            'content' => $content,
        ];
    }
}
