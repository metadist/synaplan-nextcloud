<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
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
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Ask a question, optionally with file context or RAG group.
	 *
	 * @NoAdminRequired
	 *
	 * @param string      $message  User's question
	 * @param int|null    $fileId   Nextcloud file ID for document context
	 * @param string|null $groupKey RAG group key for knowledge base context
	 * @param int|null    $modelId  Specific model ID to use
	 */
	public function chat(string $message = '', ?int $fileId = null, ?string $groupKey = null, ?int $modelId = null): JSONResponse
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

			$result = $this->synaplanClient->ask($message, $context, 'en', $groupKey, $modelId);

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
