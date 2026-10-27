<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Calls Synaplan's cloud-file state endpoints for the current person:
 * batched folder-view status, single-file detail for the sidebar, and
 * optional white-label glyph overrides from the runtime config.
 */
class CloudStateService
{
    private const TIMEOUT = 15;

    public function __construct(
        private IClientService $clientService,
        private SynaplanConfig $synaplanConfig,
        private SynaplanClient $synaplanClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Batched bird state for up to 500 files in a folder view.
     * Returns enabled=false when the core has cloud_files turned off (404).
     *
     * @param list<array{id: string, version: string}> $items
     * @return array{enabled: bool, unreachable?: bool, items: list<array<string, mixed>>, watches: list<array<string, mixed>>}
     */
    public function status(array $items, ?string $apiKey = null): array
    {
        if ($items === [] || !$this->synaplanConfig->isPerUserAccountsEnabled()) {
            return ['enabled' => false, 'items' => [], 'watches' => []];
        }
        $key = $apiKey !== null && $apiKey !== '' ? $apiKey : $this->synaplanClient->getApiKey();
        if ($key === '') {
            return ['enabled' => false, 'items' => [], 'watches' => []];
        }

        try {
            $decoded = $this->request('POST', '/api/v1/cloud/status', ['items' => array_slice($items, 0, 500)], $key);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not read Synaplan cloud file status: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);

            return ['enabled' => true, 'unreachable' => true, 'items' => [], 'watches' => []];
        }

        $status = (int) ($decoded['status_code'] ?? 200);
        if ($status === 404) {
            return ['enabled' => false, 'items' => [], 'watches' => []];
        }
        if ($status >= 400) {
            return ['enabled' => true, 'unreachable' => true, 'items' => [], 'watches' => []];
        }

        $rows = is_array($decoded['items'] ?? null) ? array_values(array_filter($decoded['items'], 'is_array')) : [];
        $watches = is_array($decoded['watches'] ?? null) ? array_values(array_filter($decoded['watches'], 'is_array')) : [];

        return [
            'enabled' => true,
            'items' => $rows,
            'watches' => $watches,
        ];
    }

    /**
     * Sidebar detail for one Synaplan file id. Null when absent or 404.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $synaplanFileId, ?string $apiKey = null): ?array
    {
        if ($synaplanFileId <= 0 || !$this->synaplanConfig->isPerUserAccountsEnabled()) {
            return null;
        }
        $key = $apiKey !== null && $apiKey !== '' ? $apiKey : $this->synaplanClient->getApiKey();
        if ($key === '') {
            return null;
        }

        try {
            $decoded = $this->request(
                'GET',
                '/api/v1/cloud/files/' . rawurlencode((string) $synaplanFileId),
                null,
                $key,
            );
        } catch (\Throwable) {
            return null;
        }

        $status = (int) ($decoded['status_code'] ?? 200);
        if ($status >= 400) {
            return null;
        }

        return $decoded;
    }

    /**
     * Optional white-label glyph URLs from /api/v1/config/runtime.
     *
     * @return array{bird: string|null, bird_eyes: string|null}
     */
    public function glyphOverrides(): array
    {
        try {
            $decoded = $this->request('GET', '/api/v1/config/runtime', null, null);
        } catch (\Throwable) {
            return ['bird' => null, 'bird_eyes' => null];
        }

        $cloudFiles = is_array($decoded['cloud_files'] ?? null) ? $decoded['cloud_files'] : [];
        $glyphs = is_array($cloudFiles['glyphs'] ?? null) ? $cloudFiles['glyphs'] : [];
        $bird = is_string($glyphs['bird'] ?? null) && $glyphs['bird'] !== '' ? $glyphs['bird'] : null;
        $eyes = is_string($glyphs['bird_eyes'] ?? null) && $glyphs['bird_eyes'] !== '' ? $glyphs['bird_eyes'] : null;

        return ['bird' => $bird, 'bird_eyes' => $eyes];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body, ?string $apiKey): array
    {
        $client = $this->clientService->newClient();
        $url = $this->synaplanConfig->getBaseUrl() . $path;
        $headers = ['Accept' => 'application/json'];
        if ($apiKey !== null && $apiKey !== '') {
            $headers['X-API-Key'] = $apiKey;
        }
        $options = [
            'headers' => $headers,
            'timeout' => self::TIMEOUT,
            'http_errors' => false,
            'nextcloud' => [
                'allow_local_address' => true,
            ],
        ];
        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body);
        }

        $response = match (strtoupper($method)) {
            'POST' => $client->post($url, $options),
            default => $client->get($url, $options),
        };
        $status = (int) $response->getStatusCode();
        $decoded = json_decode($response->getBody(), true);

        return ['status_code' => $status] + (is_array($decoded) ? $decoded : []);
    }
}
