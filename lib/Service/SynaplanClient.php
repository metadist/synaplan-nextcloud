<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Exception\FailureException;
use OCA\SynaplanIntegration\Http\Failure;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
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
    private const CAPABILITIES_TIMEOUT = 10;

    /** @var array<string, mixed>|null */
    private ?array $capabilities = null;

    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
        private SynaplanConfig $synaplanConfig,
        private UserAccountService $userAccounts,
    ) {
    }

    /**
     * Check if Synaplan is reachable.
     *
     * @return array{status?: string, providers?: array<string, bool>}
     * @throws \Exception on connection failure
     */
    public function healthCheck(): array
    {
        return $this->request('GET', '/api/health', null, false);
    }

    /**
     * Generate a document summary.
     *
     * @return array{success?: bool, summary?: string, metadata?: array<string, mixed>}
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
     * Translate a whole document into one language.
     *
     * The request is always Synaplan's translation mode. A Synaplan that
     * answered and does not list that mode is refused here: a summary in the
     * target language is not a translation. When the capability list cannot
     * be read, the translation request is still sent.
     *
     * @return array{success?: bool, summary?: string, metadata?: array<string, mixed>}
     * @throws FailureException when this Synaplan cannot translate, or the person has no key
     * @throws \Exception on API error
     */
    public function translate(string $text, string $targetLanguage): array
    {
        $this->requireApiKey();
        if ($this->knownSummaryTypesMiss('translation')) {
            throw FailureException::translationUnavailable();
        }

        return $this->request('POST', '/api/v1/summary/generate', [
            'text' => $text,
            'summaryType' => 'translation',
            'outputLanguage' => $targetLanguage,
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
     * One answer from Synaplan's chat endpoint (the OpenAI-compatible gateway the
     * streaming chat uses, here without streaming).
     *
     * @param list<array{role: string, content: string}> $messages
     * @param string|null $model Provider model id; null = the account's chat model
     * @throws \Exception on API error
     */
    public function chat(array $messages, ?string $model = null): string
    {
        $body = ['messages' => $messages, 'stream' => false];
        if ($model !== null && $model !== '') {
            $body['model'] = $model;
        }

        $result = $this->request('POST', '/v1/chat/completions', $body);

        return (string) ($result['choices'][0]['message']['content'] ?? '');
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
     * @return array{success?: bool, models?: array<string, mixed>}
     * @throws \Exception on API error
     */
    public function getModels(): array
    {
        return $this->request('GET', '/api/v1/config/models');
    }

    /**
     * Get file group keys with chunk counts.
     *
     * @return array{success?: bool, groups?: array<int, array{name: string, count: int}>}
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
     * Bulk-check which Nextcloud-sourced files drifted (CORE-4).
     *
     * @param array<int, array{source_id: string, source_etag?: string|null}> $items
     * @return array{success?: bool, results?: array<int, array{source_id: string, status?: string, file_id?: int|null, stored_etag?: string|null}>, counts?: array<string, int>}
     * @throws \Exception on API error
     */
    public function checkStale(array $items, string $source = 'nextcloud'): array
    {
        return $this->request('POST', '/api/v1/files/check-stale', [
            'source' => $source,
            'items' => array_values($items),
        ]);
    }

    /**
     * Knowledge-base status for a single Nextcloud file, derived from CORE-4's
     * check-stale: whether it is already vectorized, whether it drifted since,
     * and the Synaplan file id (needed to remove it).
     *
     * @return array{in_knowledge: bool, stale: bool, synaplan_file_id: int|null}
     */
    public function knowledgeStatus(int $ncFileId, ?string $etag): array
    {
        $result = $this->checkStale([[
            'source_id' => (string) $ncFileId,
            'source_etag' => $etag,
        ]]);

        $row = $result['results'][0] ?? null;
        $status = is_array($row) ? (string) ($row['status'] ?? 'missing') : 'missing';

        return [
            'in_knowledge' => $status !== 'missing',
            'stale' => $status === 'stale',
            'synaplan_file_id' => is_array($row) ? ($row['file_id'] ?? null) : null,
        ];
    }

    /**
     * Delete a knowledge file (and its vectors) by Synaplan file id (CORE-4
     * "remove from knowledge").
     *
     * @return array<string, mixed>
     * @throws \Exception on API error
     */
    public function deleteKnowledgeFile(int $synaplanFileId): array
    {
        return $this->request('DELETE', '/api/v1/files/' . $synaplanFileId);
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
     * @param string|null $sourceId     Stable external id (the Nextcloud file id) so the
     *                                  same file can be overwritten in place and tracked
     *                                  for staleness (CORE-4). Null for one-off uploads.
     * @param string|null $sourceEtag   Nextcloud etag captured at upload; a later change
     *                                  marks the knowledge copy stale (CORE-4).
     * @param bool        $overwrite    Replace the existing knowledge file for this
     *                                  (source, source_id) instead of creating a duplicate.
     * @return array<string, mixed>
     * @throws \Exception on API error
     */
    public function uploadFile(
        string $filename,
        string $content,
        string $groupKey,
        string $processLevel = 'vectorize',
        ?string $originalName = null,
        ?string $sourceId = null,
        ?string $sourceEtag = null,
        bool $overwrite = false,
        ?bool $keepSynced = null,
        ?string $apiKey = null,
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

        // Knowledge-file lifecycle (CORE-4): external identity + overwrite so a
        // re-added or changed Nextcloud file updates its knowledge entry in place
        // instead of piling up duplicates.
        if ($sourceId !== null && $sourceId !== '') {
            $multipart[] = ['name' => 'source_id', 'contents' => $sourceId];
        }
        if ($sourceEtag !== null && $sourceEtag !== '') {
            $multipart[] = ['name' => 'source_etag', 'contents' => $sourceEtag];
        }
        if ($overwrite) {
            $multipart[] = ['name' => 'overwrite', 'contents' => '1'];
        }
        if ($keepSynced !== null) {
            $multipart[] = ['name' => 'keep_synced', 'contents' => $keepSynced ? '1' : '0'];
        }

        $options = [
            'headers' => [
                'X-API-Key' => $apiKey !== null && $apiKey !== '' ? $apiKey : $this->requireApiKey(),
                'Accept' => 'application/json',
            ],
            'multipart' => $multipart,
            'timeout' => 180,
            'nextcloud' => [
                'allow_local_address' => true,
            ],
        ];

        try {
            $response = $client->post($url, $options);
            $decoded = json_decode($response->getBody(), true);

            if (!is_array($decoded)) {
                throw FailureException::invalidSynaplanAnswer();
            }

            return $decoded;
        } catch (\Exception $e) {
            $this->logger->error('Synaplan file upload failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);

            throw $this->refusedKey($e) ?? $e;
        }
    }

    /**
     * Generate an image or video via the Synaplan media endpoint.
     *
     * @param string   $prompt  Text description of the media to generate
     * @param string   $type    "image" or "video"
     * @param int|null $modelId Specific model ID (uses user default if null)
     * @return array{success?: bool, file?: array{url: string, type: string, mimeType: string}, provider?: string, model?: string}
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
                'X-API-Key' => $this->requireApiKey(),
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
                throw FailureException::invalidSynaplanAnswer();
            }

            return $decoded;
        } catch (\Exception $e) {
            $this->logger->error('Synaplan media generation failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
                'type' => $type,
            ]);

            throw $this->refusedKey($e) ?? $e;
        }
    }

    /**
     * Download a file from Synaplan by its relative upload path.
     *
     * @param string $relativePath Relative file URL (e.g. "/api/v1/files/uploads/...")
     * @return string Raw binary content
     * @throws \Exception on download failure
     */
    /**
     * Recent files in the signed-in person's Synaplan, newest first.
     *
     * @return array<string, mixed>
     */
    public function listRecentFiles(int $page = 1): array
    {
        return $this->request('GET', '/api/v1/files', null, true, [
            'page' => max(1, $page),
            'limit' => 50,
        ]);
    }

    /**
     * The bytes of one file the person owns in Synaplan.
     */
    public function downloadStoredFile(int $id): string
    {
        return $this->downloadFile('/api/v1/files/' . $id . '/download');
    }

    public function downloadFile(string $relativePath): string
    {
        $client = $this->clientService->newClient();
        $url = $this->getBaseUrl() . $relativePath;

        $options = [
            'headers' => [
                'X-API-Key' => $this->requireApiKey(),
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

            throw $this->refusedKey($e) ?? $e;
        }
    }

    /**
     * Active backend environment: 'live' (production) or 'local' (development).
     * Lets developers flip the whole app between backends with one switch.
     */
    public function getActiveEnv(): string
    {
        return $this->synaplanConfig->getActiveEnv();
    }

    public function getBaseUrl(): string
    {
        return $this->synaplanConfig->getBaseUrl();
    }

    /**
     * The address people open in the browser (falls back to the API URL).
     */
    public function getPublicBaseUrl(): string
    {
        return $this->synaplanConfig->getPublicBaseUrl();
    }

    /**
     * The API key to use for the CURRENT request.
     *
     * In per-user mode this is the logged-in Nextcloud user's own Synaplan key
     * (provisioned + minted on first use, AFTER the user activates AI). If the
     * user has no key yet — because they have not consented, or provisioning
     * failed — the key is EMPTY on purpose, never the shared admin key, which
     * would break both isolation and the consent gate. The admin key is used
     * only internally for provisioning (see UserAccountService).
     *
     * In shared-key mode (per-user off) the single install-wide key is used,
     * preserving the original behaviour.
     */
    public function getApiKey(): string
    {
        if ($this->synaplanConfig->isPerUserAccountsEnabled()) {
            return $this->userAccounts->getCurrentUserApiKey() ?? '';
        }

        return $this->synaplanConfig->getAdminApiKey();
    }

    /**
     * True when Synaplan answered and its summary types do not include $type.
     * An unreadable answer is not a refusal: the request is still sent.
     */
    private function knownSummaryTypesMiss(string $type): bool
    {
        $summary = $this->capabilities()['summary'] ?? null;
        $types = is_array($summary) ? ($summary['types'] ?? null) : null;

        return is_array($types) && !in_array($type, $types, true);
    }

    /**
     * What the connected Synaplan supports, read once per request. The endpoint
     * is public and is called without the user's key: Synaplan refuses a
     * restricted key on any path outside its scopes.
     *
     * @return array<string, mixed>
     */
    private function capabilities(): array
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }

        try {
            $response = $this->clientService->newClient()->get($this->getBaseUrl() . '/api/v1/config/capabilities', [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::CAPABILITIES_TIMEOUT,
                'nextcloud' => [
                    'allow_local_address' => true,
                ],
            ]);
            $decoded = json_decode($response->getBody(), true);
            $this->capabilities = is_array($decoded) ? $decoded : [];
        } catch (\Exception $e) {
            $this->logger->warning('Synaplan capabilities could not be read: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
            $this->capabilities = [];
        }

        return $this->capabilities;
    }

    /**
     * The current request's key, or the failure that says what the person can
     * do: connect their account (link mode), activate AI, or ask the
     * administrator because provisioning failed. Nothing is sent without a key.
     *
     * @throws FailureException
     */
    public function requireApiKey(): string
    {
        $key = $this->getApiKey();
        if ($key !== '' || !$this->synaplanConfig->isPerUserAccountsEnabled()) {
            return $key;
        }
        if ($this->synaplanConfig->isLinkMode()) {
            throw FailureException::notConnected();
        }

        throw $this->userAccounts->hasConsentForCurrentUser()
            ? FailureException::accountUnavailable()
            : FailureException::consentRequired();
    }

    /**
     * Synaplan refused the person's key in a request that is not repeated (a
     * stream, an upload, media): forget the key and say what to do. A linked
     * person has to connect again; a provisioned one gets a new key with the
     * next request.
     */
    public function keyRefused(): Failure
    {
        if (!$this->synaplanConfig->isPerUserAccountsEnabled()) {
            return new Failure(Http::STATUS_BAD_GATEWAY, Failure::SYNAPLAN_KEY_REJECTED);
        }
        $this->userAccounts->clearCurrentUserApiKey();
        if (!$this->synaplanConfig->isLinkMode()) {
            return new Failure(Http::STATUS_BAD_GATEWAY, Failure::SYNAPLAN_ERROR);
        }
        $this->userAccounts->clearLinkPrefs();

        return FailureException::notConnected()->failure;
    }

    /**
     * The failure for a 401 in the per-user modes, null for anything else.
     */
    private function refusedKey(\Exception $e): ?FailureException
    {
        if (!$this->isUnauthorized($e) || !$this->synaplanConfig->isPerUserAccountsEnabled()) {
            return null;
        }

        return new FailureException($this->keyRefused(), 'Synaplan refused the key: ' . $e->getMessage());
    }

    /**
     * @param bool $requiresKey false for public routes, which work without an account
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     * @throws \Exception on HTTP or decode error
     */
    private function request(string $method, string $path, ?array $body = null, bool $requiresKey = true, array $query = []): array
    {
        $client = $this->clientService->newClient();
        $url = $this->getBaseUrl() . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $options = [
            'headers' => [
                'X-API-Key' => $requiresKey ? $this->requireApiKey() : $this->getApiKey(),
                'Accept' => 'application/json',
            ],
            'timeout' => self::TIMEOUT,
            'nextcloud' => [
                'allow_local_address' => true,
            ],
        ];

        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body);
        }

        try {
            $response = $this->doRequest($client, $method, $url, $options);
            $decoded = json_decode($response->getBody(), true);

            if (!is_array($decoded)) {
                throw FailureException::invalidSynaplanAnswer();
            }

            return $decoded;
        } catch (\Exception $e) {
            // Self-heal a stale/revoked per-user key. In provision mode, drop
            // it and retry once so the next getApiKey() re-provisions. In
            // link mode a 401 means the user disconnected — clear prefs and
            // do not remint (the UI shows Connect again).
            if ($this->isUnauthorized($e) && $this->synaplanConfig->isPerUserAccountsEnabled()) {
                $this->userAccounts->clearCurrentUserApiKey();
                if ($this->synaplanConfig->isLinkMode()) {
                    $this->userAccounts->clearLinkPrefs();
                    $this->logger->info('Synaplan refused the linked key, the user has to connect again: {message}', [
                        'app' => Application::APP_ID,
                        'message' => $e->getMessage(),
                        'method' => $method,
                        'path' => $path,
                    ]);

                    throw FailureException::notConnected();
                }
                try {
                    $options['headers']['X-API-Key'] = $this->requireApiKey();
                    $response = $this->doRequest($this->clientService->newClient(), $method, $url, $options);
                    $decoded = json_decode($response->getBody(), true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                    $e = FailureException::invalidSynaplanAnswer();
                } catch (\Exception $retry) {
                    $e = $this->isUnauthorized($retry) ? FailureException::accountUnavailable() : $retry;
                }
            }

            $this->logger->error('Synaplan API request failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
                'method' => $method,
                'path' => $path,
            ]);

            throw $e;
        }
    }

    /**
     * Whether Synaplan answered 401. The Nextcloud HTTP client throws Guzzle's
     * exceptions, which carry the HTTP status as their code. The message holds
     * the URL and transport errors, so a "401" in it proves nothing.
     */
    private function isUnauthorized(\Exception $e): bool
    {
        return $e->getCode() === Http::STATUS_UNAUTHORIZED;
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
