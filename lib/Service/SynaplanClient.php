<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for the Synaplan API.
 *
 * Uses X-API-Key authentication for all requests.
 * No Nextcloud-specific business logic — only HTTP transport.
 */
class SynaplanClient
{
    private const TIMEOUT = 120;
    private const MEDIA_TIMEOUT = 180;

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Check if Synaplan is reachable.
     *
     * @return array{status: string, providers?: array<string, bool>}
     * @throws \Exception on connection failure
     */
    public function healthCheck(): array
    {
        return $this->request('GET', '/api/health');
    }

    /**
     * Generate a document summary.
     *
     * @return array{success: bool, summary: string, metadata?: array<string, mixed>}
     * @throws \Exception on API error
     */
    public function summarize(
        string $text,
        string $summaryType = 'bullet-points',
        string $length = 'medium',
        string $outputLanguage = 'en',
    ): array {
        return $this->request('POST', '/api/v1/summary/generate', [
            'text' => $text,
            'summaryType' => $summaryType,
            'length' => $length,
            'outputLanguage' => $outputLanguage,
        ]);
    }

    /**
     * Create a new chat session.
     *
     * @return array<string, mixed>
     * @throws \Exception on API error
     */
    public function createChat(string $title): array
    {
        return $this->request('POST', '/api/v1/chats', [
            'title' => $title,
        ]);
    }

    /**
     * Ask a question about given context, optionally with RAG and model selection.
     *
     * When a groupKey is provided, first searches the Synaplan RAG knowledge base
     * for relevant context, then combines it with any provided file context.
     *
     * @return array<string, mixed>
     * @throws \Exception on API error
     */
    public function ask(
        string $question,
        string $context = '',
        string $outputLanguage = 'en',
        ?string $groupKey = null,
        ?int $modelId = null,
    ): array {
        // If a group key is provided, search RAG for relevant context
        if ($groupKey !== null && $groupKey !== '') {
            $ragContext = $this->searchRagContext($question, $groupKey);
            if ($ragContext !== '') {
                // Prepend RAG context to any existing file context
                $context = $ragContext . ($context !== '' ? "\n\n" . $context : '');
            }
        }

        $prompt = $context !== ''
            ? "Based on the following context, answer the user's question.\n\n"
                . "--- CONTEXT ---\n" . $context . "\n--- END CONTEXT ---\n\n"
                . 'Question: ' . $question
            : $question;

        $body = [
            'text' => $prompt,
            'summaryType' => 'abstractive',
            'length' => 'long',
            'outputLanguage' => $outputLanguage,
        ];

        if ($modelId !== null) {
            $body['modelId'] = $modelId;
        }

        return $this->request('POST', '/api/v1/summary/generate', $body);
    }

    /**
     * Search the RAG knowledge base and return concatenated context text.
     *
     * Failures are logged and swallowed (returns '') so chat can continue
     * without knowledge-base context rather than erroring out.
     */
    public function searchRagContext(string $query, string $groupKey, int $limit = 5, float $minScore = 0.3): string
    {
        if ($groupKey === '') {
            return '';
        }

        try {
            $ragResults = $this->request('POST', '/api/v1/rag/search', [
                'query' => $query,
                'group_key' => $groupKey,
                'limit' => $limit,
                'min_score' => $minScore,
            ]);

            if (!empty($ragResults['results'])) {
                $ragContext = '';
                foreach ($ragResults['results'] as $result) {
                    $ragContext .= ($result['text'] ?? '') . "\n\n";
                }

                return trim($ragContext);
            }
        } catch (\Exception $e) {
            $this->logger->warning('RAG search failed, continuing without context: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
        }

        return '';
    }

    /**
     * Check whether the Synaplan memory (Qdrant) service is reachable.
     *
     * Never throws — a failure simply means "memories not available", which the
     * chat UI uses to hide the memory toggle gracefully.
     *
     * @return array{available: bool, configured: bool}
     */
    public function checkMemoryService(): array
    {
        try {
            $result = $this->request('GET', '/api/v1/config/memory-service/check');

            return [
                'available' => (bool) ($result['available'] ?? false),
                'configured' => (bool) ($result['configured'] ?? false),
            ];
        } catch (\Exception $e) {
            $this->logger->info('Memory service check failed, treating as unavailable: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);

            return ['available' => false, 'configured' => false];
        }
    }

    /**
     * Search the user's personal memories and return them as a context string.
     *
     * Failures are logged and swallowed (returns '') so chat can continue
     * without memory context rather than erroring out.
     */
    public function searchMemoryContext(string $query, int $limit = 6): string
    {
        if (trim($query) === '') {
            return '';
        }

        try {
            $result = $this->request('POST', '/api/v1/user/memories/search', [
                'query' => $query,
                'limit' => $limit,
            ]);

            if (empty($result['memories']) || !is_array($result['memories'])) {
                return '';
            }

            $lines = [];
            foreach ($result['memories'] as $memory) {
                if (!is_array($memory)) {
                    continue;
                }
                $key = trim((string) ($memory['key'] ?? ''));
                $value = trim((string) ($memory['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $lines[] = $key !== '' ? ('- ' . $key . ': ' . $value) : ('- ' . $value);
            }

            return implode("\n", $lines);
        } catch (\Exception $e) {
            $this->logger->warning('Memory search failed, continuing without memories: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Get available AI models grouped by capability.
     *
     * @return array{success: bool, models: array<string, array<int, array<string, mixed>>>}
     * @throws \Exception on API error
     */
    public function getModels(): array
    {
        return $this->request('GET', '/api/v1/config/models');
    }

    /**
     * Get file group keys with chunk counts.
     *
     * @return array{success: bool, groups: array<int, array{name: string, count: int}>}
     * @throws \Exception on API error
     */
    public function getFileGroups(): array
    {
        return $this->request('GET', '/api/v1/files/groups');
    }

    /**
     * Get the extracted text content of an uploaded file.
     *
     * @param int $fileId Synaplan file ID (returned from uploadFile)
     * @return array<string, mixed>
     * @throws \Exception on API error
     */
    public function getFileContent(int $fileId): array
    {
        return $this->request('GET', '/api/v1/files/' . $fileId . '/content');
    }

    /**
     * Upload a file to the Synaplan knowledge base.
     *
     * @param string      $filename     Original filename
     * @param string      $content      File binary content
     * @param string      $groupKey     Target group key for vectorization
     * @param string      $processLevel store|extract|vectorize|full
     * @param string|null $originalName Source-side name (e.g. Nextcloud path/basename),
     *                                  preserved as provenance. Defaults to $filename.
     * @return array<string, mixed>
     * @throws \Exception on API error
     */
    public function uploadFile(
        string $filename,
        string $content,
        string $groupKey,
        string $processLevel = 'vectorize',
        ?string $originalName = null,
    ): array {
        $client = $this->clientService->newClient();
        $url = $this->getBaseUrl() . '/api/v1/files/upload';

        $multipart = [
            [
                'name' => 'files[]',
                'contents' => $content,
                'filename' => $filename,
            ],
            [
                'name' => 'group_key',
                'contents' => $groupKey,
            ],
            [
                'name' => 'process_level',
                'contents' => $processLevel,
            ],
            // Provenance: tell Synaplan this file came from Nextcloud so it is
            // labelled by source in the file world (03_file-management.md §3.1).
            [
                'name' => 'source',
                'contents' => 'nextcloud',
            ],
            [
                'name' => 'original_name',
                'contents' => $originalName ?? $filename,
            ],
        ];

        $options = [
            'headers' => [
                'X-API-Key' => $this->getApiKey(),
                'Accept' => 'application/json',
            ],
            'multipart' => $multipart,
            'timeout' => 180,
        ];

        try {
            $response = $client->post($url, $options);
            $decoded = json_decode($response->getBody(), true);

            if (!is_array($decoded)) {
                throw new \RuntimeException('Invalid JSON response from Synaplan API');
            }

            return $decoded;
        } catch (\Exception $e) {
            $this->logger->error('Synaplan file upload failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate an image or video via the Synaplan media endpoint.
     *
     * @param string   $prompt  Text description of the media to generate
     * @param string   $type    "image" or "video"
     * @param int|null $modelId Specific model ID (uses user default if null)
     * @return array{success: bool, file: array{url: string, type: string, mimeType: string}, provider: string, model: string}
     * @throws \Exception on API error
     */
    public function generateMedia(string $prompt, string $type, ?int $modelId = null): array
    {
        $body = [
            'prompt' => $prompt,
            'type' => $type,
        ];

        if ($modelId !== null) {
            $body['modelId'] = $modelId;
        }

        $client = $this->clientService->newClient();
        $url = $this->getBaseUrl() . '/api/v1/media/generate';

        $options = [
            'headers' => [
                'X-API-Key' => $this->getApiKey(),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
            'timeout' => self::MEDIA_TIMEOUT,
        ];

        try {
            $response = $client->post($url, $options);
            $decoded = json_decode($response->getBody(), true);

            if (!is_array($decoded)) {
                throw new \RuntimeException('Invalid JSON response from Synaplan API');
            }

            return $decoded;
        } catch (\Exception $e) {
            $this->logger->error('Synaplan media generation failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
                'type' => $type,
            ]);

            throw $e;
        }
    }

    /**
     * Download a file from Synaplan by its relative upload path.
     *
     * @param string $relativePath Relative file URL (e.g. "/api/v1/files/uploads/...")
     * @return string Raw binary content
     * @throws \Exception on download failure
     */
    public function downloadFile(string $relativePath): string
    {
        $client = $this->clientService->newClient();
        $url = $this->getBaseUrl() . $relativePath;

        $options = [
            'headers' => [
                'X-API-Key' => $this->getApiKey(),
            ],
            'timeout' => self::MEDIA_TIMEOUT,
        ];

        try {
            $response = $client->get($url, $options);

            return $response->getBody();
        } catch (\Exception $e) {
            $this->logger->error('Synaplan file download failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
                'path' => $relativePath,
            ]);

            throw $e;
        }
    }

    public function getBaseUrl(): string
    {
        return rtrim(
            $this->config->getAppValue(Application::APP_ID, 'synaplan_url', 'http://localhost:8000'),
            '/'
        );
    }

    public function getApiKey(): string
    {
        return $this->config->getAppValue(Application::APP_ID, 'api_key', '');
    }

    /**
     * @return array<string, mixed>
     * @throws \Exception on HTTP or decode error
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $client = $this->clientService->newClient();
        $url = $this->getBaseUrl() . $path;

        $options = [
            'headers' => [
                'X-API-Key' => $this->getApiKey(),
                'Accept' => 'application/json',
            ],
            'timeout' => self::TIMEOUT,
        ];

        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body);
        }

        try {
            $response = $this->doRequest($client, $method, $url, $options);
            $decoded = json_decode($response->getBody(), true);

            if (!is_array($decoded)) {
                throw new \RuntimeException('Invalid JSON response from Synaplan API');
            }

            return $decoded;
        } catch (\Exception $e) {
            $this->logger->error('Synaplan API request failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
                'method' => $method,
                'path' => $path,
            ]);

            throw $e;
        }
    }

    private function doRequest(IClient $client, string $method, string $url, array $options): \OCP\Http\Client\IResponse
    {
        return match (strtoupper($method)) {
            'GET' => $client->get($url, $options),
            'POST' => $client->post($url, $options),
            'PUT' => $client->put($url, $options),
            'DELETE' => $client->delete($url, $options),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };
    }
}
