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
            try {
                $ragResults = $this->request('POST', '/api/v1/rag/search', [
                    'query' => $question,
                    'group_key' => $groupKey,
                    'limit' => 5,
                    'min_score' => 0.3,
                ]);

                if (!empty($ragResults['results'])) {
                    $ragContext = '';
                    foreach ($ragResults['results'] as $result) {
                        $ragContext .= ($result['text'] ?? '') . "\n\n";
                    }
                    // Prepend RAG context to any existing file context
                    $context = trim($ragContext) . ($context !== '' ? "\n\n" . $context : '');
                }
            } catch (\Exception $e) {
                $this->logger->warning('RAG search failed, continuing without context: {message}', [
                    'app' => Application::APP_ID,
                    'message' => $e->getMessage(),
                ]);
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
     * @param string $filename Original filename
     * @param string $content  File binary content
     * @param string $groupKey Target group key for vectorization
     * @return array<string, mixed>
     * @throws \Exception on API error
     */
    public function uploadFile(string $filename, string $content, string $groupKey, string $processLevel = 'vectorize'): array
    {
        $client = $this->clientService->newClient();
        $url = $this->getBaseUrl() . '/api/v1/files/upload';

        $options = [
            'headers' => [
                'X-API-Key' => $this->getApiKey(),
                'Accept' => 'application/json',
            ],
            'multipart' => [
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
            ],
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
