<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Http\RespondsToFailures;
use OCA\SynaplanIntegration\Http\SseProxyResponse;
use OCA\SynaplanIntegration\Service\LanguageService;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
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
    use RespondsToFailures;

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
     * @param string      $message     User's question
     * @param int|null    $fileId      Nextcloud file ID for document context
     * @param string|null $groupKey    RAG group key for knowledge base context
     * @param string|null $model       Provider model id (null = the account's chat model)
     * @param bool        $useMemories Whether to enrich the answer with the user's memories
     * @param list<mixed> $history Earlier turns of this Research Chat. Ignored for a file.
     */
    #[NoAdminRequired]
    public function chat(string $message = '', ?int $fileId = null, ?string $groupKey = null, ?string $model = null, bool $useMemories = false, array $history = []): JSONResponse
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
                    $model = isset($decoded['model']) ? trim((string) $decoded['model']) : $model;
                    $useMemories = isset($decoded['useMemories']) ? (bool) $decoded['useMemories'] : $useMemories;
                    $history = isset($decoded['history']) && is_array($decoded['history']) ? $decoded['history'] : $history;
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
            $answer = $this->synaplanClient->chat(
                $this->messages($message, $fileId, $groupKey, $useMemories, $history),
                $model,
            );

            return new JSONResponse([
                'success' => true,
                'response' => $answer,
                'deepLink' => $this->synaplanClient->getPublicBaseUrl(),
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'Chat failed');
        }
    }

    /**
     * Ask a question and stream the answer token-by-token via SSE.
     *
     * Proxies Synaplan's OpenAI-compatible `/v1/chat/completions` streaming
     * endpoint. RAG (group) and file context are resolved here, exactly like
     * {@see self::chat()}, then relayed to the browser as it arrives.
     *
     * @param string      $message     User's question
     * @param int|null    $fileId      Nextcloud file ID for document context
     * @param string|null $groupKey    RAG group key for knowledge base context
     * @param string|null $model       Provider model id/name (null = user default)
     * @param bool        $useMemories Whether to enrich the answer with the user's memories
     * @param list<mixed> $history Earlier turns of this Research Chat. Ignored for a file.
     */
    #[NoAdminRequired]
    public function chatStream(string $message = '', ?int $fileId = null, ?string $groupKey = null, ?string $model = null, bool $useMemories = false, array $history = []): Response
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
                    $history = isset($decoded['history']) && is_array($decoded['history']) ? $decoded['history'] : $history;
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
            $payload = ['messages' => $this->messages($message, $fileId, $groupKey, $useMemories, $history), 'stream' => true];
            if ($model !== null && $model !== '') {
                $payload['model'] = $model;
            }

            return new SseProxyResponse(
                $this->synaplanClient->getBaseUrl() . '/v1/chat/completions',
                $this->synaplanClient->requireApiKey(),
                (string) json_encode($payload),
                $this->logger,
                fn () => $this->synaplanClient->keyRefused(),
            );
        } catch (\Exception $e) {
            return $this->failed($e, 'Chat stream failed');
        }
    }

    /**
     * The system prompt and the question with its file, folder and memory context.
     *
     * @param list<mixed> $history
     *
     * @return list<array{role: string, content: string}>
     * @throws NotFoundException when the file is not the user's
     */
    private function messages(string $message, ?int $fileId, ?string $groupKey, bool $useMemories, array $history = []): array
    {
        $context = $fileId !== null ? $this->getFileInfo($fileId)['content'] : '';

        if ($groupKey !== null && $groupKey !== '') {
            $ragContext = $this->synaplanClient->searchRagContext($message, $groupKey);
            if ($ragContext !== '') {
                $context = $ragContext . ($context !== '' ? "\n\n" . $context : '');
            }
        }

        $memoryContext = $useMemories ? $this->synaplanClient->searchMemoryContext($message) : '';

        $userContent = $context !== ''
            ? "Based on the following context, answer the user's question.\n\n"
                . "--- CONTEXT ---\n" . $context . "\n--- END CONTEXT ---\n\n"
                . 'Question: ' . $message
            : $message;

        $turns = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($memoryContext)],
        ];
        // A file action is one question about that file. Research Chat sends
        // the earlier wording so the next answer stays in the same thread.
        if ($fileId === null) {
            foreach (array_slice($history, -8) as $turn) {
                if (!is_array($turn)) {
                    continue;
                }
                $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : (($turn['role'] ?? '') === 'user' ? 'user' : '');
                $text = trim((string) ($turn['text'] ?? ''));
                if ($role === '' || $text === '') {
                    continue;
                }
                $turns[] = ['role' => $role, 'content' => mb_substr($text, 0, 2000)];
            }
        }
        $turns[] = ['role' => 'user', 'content' => $userContent];

        return $turns;
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
