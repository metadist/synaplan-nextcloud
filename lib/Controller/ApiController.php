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
 * API controller for document AI operations.
 *
 * Provides summarization and translation of Nextcloud files
 * via the Synaplan API.
 */
class ApiController extends Controller
{
	/** Max file size for direct text extraction (5 MB). */
	private const MAX_TEXT_FILE_SIZE = 5 * 1024 * 1024;

	/** Max file size for binary upload to Synaplan (20 MB). */
	private const MAX_UPLOAD_FILE_SIZE = 20 * 1024 * 1024;

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
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Summarize a file.
	 *
	 * @NoAdminRequired
	 *
	 * @param int    $fileId         Nextcloud file ID
	 * @param string $summaryType    bullet-points|abstractive|extractive
	 * @param string $length         short|medium|long
	 * @param string $outputLanguage ISO language code (en, de, fr, es, it)
	 */
	public function summarize(
		int $fileId,
		string $summaryType = 'bullet-points',
		string $length = 'medium',
		string $outputLanguage = 'en',
	): JSONResponse {
		try {
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
		} catch (NotFoundException $e) {
			return new JSONResponse(
				['success' => false, 'error' => 'File not found'],
				Http::STATUS_NOT_FOUND,
			);
		} catch (\Exception $e) {
			$this->logger->error('Summarization failed: {message}', [
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
	 * Translate a file.
	 *
	 * Uses Synaplan's summarization endpoint with abstractive/long
	 * settings to produce a full translation.
	 *
	 * @NoAdminRequired
	 *
	 * @param int    $fileId         Nextcloud file ID
	 * @param string $targetLanguage ISO language code (en, de, fr, es, it)
	 */
	public function translate(
		int $fileId,
		string $targetLanguage = 'en',
	): JSONResponse {
		try {
			$text = $this->extractFileText($fileId);

			$result = $this->synaplanClient->summarize(
				$text,
				'abstractive',
				'long',
				$targetLanguage,
			);

			return new JSONResponse([
				'success' => true,
				'translation' => $result['summary'] ?? '',
				'metadata' => $result['metadata'] ?? null,
			]);
		} catch (NotFoundException $e) {
			return new JSONResponse(
				['success' => false, 'error' => 'File not found'],
				Http::STATUS_NOT_FOUND,
			);
		} catch (\Exception $e) {
			$this->logger->error('Translation failed: {message}', [
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
	 * Upload a Nextcloud file to the Synaplan knowledge base.
	 *
	 * Sends the file content to Synaplan for text extraction and vectorization.
	 *
	 * @NoAdminRequired
	 *
	 * @param int    $fileId   Nextcloud file ID
	 * @param string $groupKey Target group key for vectorization
	 */
	public function uploadToKnowledge(int $fileId, string $groupKey = 'DEFAULT'): JSONResponse
	{
		// Parse JSON body for groupKey
		$body = file_get_contents('php://input');
		if ($body !== false && $body !== '') {
			$decoded = json_decode($body, true);
			if (is_array($decoded) && isset($decoded['groupKey'])) {
				$groupKey = trim((string) $decoded['groupKey']);
			}
		}

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
				throw new \RuntimeException(
					'File too large (max ' . (self::MAX_UPLOAD_FILE_SIZE / 1024 / 1024) . ' MB)',
				);
			}

			$content = $node->getContent();
			$filename = $node->getName();

			$result = $this->synaplanClient->uploadFile($filename, $content, $groupKey);

			$fileInfo = $result['files'][0] ?? null;

			return new JSONResponse([
				'success' => $result['success'] ?? false,
				'filename' => $filename,
				'groupKey' => $groupKey,
				'chunksCreated' => $fileInfo['chunks_created'] ?? 0,
				'vectorized' => $fileInfo['vectorized'] ?? false,
				'extractedTextLength' => $fileInfo['extracted_text_length'] ?? 0,
			]);
		} catch (NotFoundException $e) {
			return new JSONResponse(
				['success' => false, 'error' => 'File not found'],
				Http::STATUS_NOT_FOUND,
			);
		} catch (\Exception $e) {
			$this->logger->error('Knowledge upload failed: {message}', [
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
	 * Get available file groups from Synaplan.
	 *
	 * @NoAdminRequired
	 */
	public function getGroups(): JSONResponse
	{
		try {
			$result = $this->synaplanClient->getFileGroups();

			return new JSONResponse([
				'success' => true,
				'groups' => $result['groups'] ?? [],
			]);
		} catch (\Exception $e) {
			$this->logger->error('Failed to fetch groups: {message}', [
				'app' => Application::APP_ID,
				'message' => $e->getMessage(),
			]);

			return new JSONResponse(
				['success' => false, 'error' => $e->getMessage(), 'groups' => []],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}

	/**
	 * Get available AI models from Synaplan.
	 *
	 * @NoAdminRequired
	 */
	public function getModels(): JSONResponse
	{
		try {
			$result = $this->synaplanClient->getModels();
			$models = $result['models'] ?? [];

			// Extract CHAT models for the research chat
			$chatModels = [];
			if (isset($models['CHAT']) && is_array($models['CHAT'])) {
				foreach ($models['CHAT'] as $model) {
					$chatModels[] = [
						'id' => $model['id'] ?? 0,
						'name' => $model['name'] ?? 'Unknown',
						'service' => $model['service'] ?? '',
						'providerId' => $model['providerId'] ?? '',
					];
				}
			}

			return new JSONResponse([
				'success' => true,
				'models' => $chatModels,
			]);
		} catch (\Exception $e) {
			$this->logger->error('Failed to fetch models: {message}', [
				'app' => Application::APP_ID,
				'message' => $e->getMessage(),
			]);

			return new JSONResponse(
				['success' => false, 'error' => $e->getMessage(), 'models' => []],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
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
	 * @throws \RuntimeException if file type or size is unsupported
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
				throw new \RuntimeException(
					'File too large (max ' . (self::MAX_TEXT_FILE_SIZE / 1024 / 1024) . ' MB)',
				);
			}

			$content = $node->getContent();
			if ($content === '') {
				throw new \RuntimeException('File is empty');
			}

			return $content;
		}

		// Binary documents: upload to Synaplan for Tika extraction, then fetch text
		if ($this->isBinaryDocumentType($mimeType)) {
			if ($node->getSize() > self::MAX_UPLOAD_FILE_SIZE) {
				throw new \RuntimeException(
					'File too large (max ' . (self::MAX_UPLOAD_FILE_SIZE / 1024 / 1024) . ' MB)',
				);
			}

			// Step 1: Upload file — Synaplan extracts text via Tika and stores it
			$uploadResult = $this->synaplanClient->uploadFile(
				$node->getName(),
				$node->getContent(),
				'_nextcloud_temp',
				'extract',
			);

			$fileInfo = $uploadResult['files'][0] ?? null;
			if ($fileInfo === null) {
				throw new \RuntimeException(
					'Upload to Synaplan failed for ' . $node->getName(),
				);
			}

			$synaplanFileId = $fileInfo['id'] ?? null;
			$extractedLength = $fileInfo['extracted_text_length'] ?? 0;

			if ($synaplanFileId === null || $extractedLength === 0) {
				throw new \RuntimeException(
					'Could not extract text from ' . $node->getName()
						. '. The file may be image-only or password-protected.',
				);
			}

			// Step 2: Fetch the extracted text via the content endpoint
			$contentResult = $this->synaplanClient->getFileContent($synaplanFileId);
			$extractedText = $contentResult['extracted_text'] ?? '';

			if ($extractedText === '') {
				throw new \RuntimeException(
					'Could not retrieve extracted text from ' . $node->getName()
						. '. The file may be image-only or password-protected.',
				);
			}

			return $extractedText;
		}

		throw new \RuntimeException(
			'Unsupported file type: ' . $mimeType
				. '. Supported: text, PDF, DOCX, ODT, XLSX, PPTX.',
		);
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
