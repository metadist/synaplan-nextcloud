<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Exception\PlatformLinkException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Server-to-server handshake with Synaplan's platform-links API.
 *
 * Talks via IClientService (never SynaplanClient) so a 401 on a user key
 * cannot re-enter registration or exchange.
 */
class PlatformLinkService
{
    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private ICrypto $crypto,
        private SynaplanConfig $synaplanConfig,
        private UserAccountService $userAccounts,
        private LoggerInterface $logger,
    ) {
    }

    public function callbackUrl(): string
    {
        return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.link.callback');
    }

    public function personalSettingsUrl(array $query = []): string
    {
        $url = $this->urlGenerator->linkToRouteAbsolute('settings.PersonalSettings.index', [
            'section' => Application::APP_ID,
        ]);
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        return $url;
    }

    public function connectUrl(string $instanceId, string $externalId, string $state): string
    {
        $params = [
            'client' => 'nextcloud',
            'instance_id' => $instanceId,
            'external_id' => $externalId,
            'state' => $state,
            'redirect_uri' => $this->callbackUrl(),
        ];
        if ($this->synaplanConfig->isMemoriesEnabled()) {
            $params['with_memories'] = '1';
        }

        return $this->synaplanConfig->getPublicBaseUrl() . '/connect/platform?' . http_build_query($params);
    }

    /**
     * Register this Nextcloud instance with Synaplan.
     *
     * @return array{instance_id: string, status: string, host: string}
     */
    public function registerInstance(?string $adminKey = null): array
    {
        $callback = $this->callbackUrl();
        $host = $this->publicHostFromUrl($callback);
        $key = $adminKey ?? $this->synaplanConfig->getAdminApiKey();
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if ($key !== '') {
            $headers['X-API-Key'] = $key;
        }

        $decoded = $this->request('POST', '/api/v1/platform-links/instances', [
            'client' => 'nextcloud',
            'host' => $host,
            'redirect_uris' => [$callback],
        ], $headers);

        $instanceId = (string) ($decoded['instance_id'] ?? '');
        $secret = (string) ($decoded['instance_secret'] ?? '');
        if ($instanceId === '' || $secret === '') {
            throw new PlatformLinkException(
                'Registration response missing credentials.',
                PlatformLinkException::CODE_EXCHANGE
            );
        }

        $this->config->setAppValue(Application::APP_ID, 'link_instance_id', $instanceId);
        $this->config->setAppValue(
            Application::APP_ID,
            'link_instance_secret',
            $this->crypto->encrypt($secret)
        );
        try {
            $this->announceDelivery($this->defaultDeliveryUrl());
        } catch (\Throwable $e) {
            $this->logger->warning('Could not tell Synaplan where to reach this Nextcloud: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
        }

        $this->logger->info('Registered Nextcloud instance with Synaplan ({status})', [
            'app' => Application::APP_ID,
            'status' => (string) ($decoded['status'] ?? ''),
            'instance_id' => $instanceId,
        ]);

        return [
            'instance_id' => $instanceId,
            'status' => (string) ($decoded['status'] ?? 'pending'),
            'host' => $host,
        ];
    }

    /**
     * @return array{status: string, host: string, client: string}|array{error: string}
     */
    public function instanceStatus(): array
    {
        $instanceId = $this->synaplanConfig->getLinkInstanceId();
        $secret = $this->decryptSecret();
        if ($instanceId === '' || $secret === '') {
            return ['error' => 'not_registered'];
        }

        try {
            $decoded = $this->request('GET', '/api/v1/platform-links/instances/self', null, [
                'Accept' => 'application/json',
                'X-Instance-Id' => $instanceId,
                'X-Instance-Secret' => $secret,
            ]);

            return [
                'status' => (string) ($decoded['status'] ?? ''),
                'host' => (string) ($decoded['host'] ?? ''),
                'client' => (string) ($decoded['client'] ?? 'nextcloud'),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Could not read Synaplan instance status: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);

            return ['error' => 'unreachable'];
        }
    }

    /**
     * The address Synaplan's servers call. An empty setting means this
     * server. A browser address of localhost is the dev stack, which Synaplan
     * reaches as http://nextcloud.
     */
    public function defaultDeliveryUrl(): string
    {
        $configured = trim((string) $this->config->getAppValue(Application::APP_ID, 'delivery_url', ''));
        if ($configured !== '') {
            return $configured;
        }
        $absolute = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.inbound.receive');
        $host = strtolower((string) parse_url($absolute, PHP_URL_HOST));
        if (in_array($host, ['localhost', '127.0.0.1'], true)) {
            $path = (string) parse_url($absolute, PHP_URL_PATH);

            return 'http://nextcloud' . $path;
        }

        return $absolute;
    }

    /**
     * Tell Synaplan where to call, which server this is, and what this app does.
     * server_id is this Nextcloud's own instance id, which adoption checks.
     */
    public function announceDelivery(string $deliveryUrl): void
    {
        $this->request('PATCH', '/api/v1/platform-links/instances/self', [
            'delivery_url' => $deliveryUrl,
            'server_id' => $this->synaplanConfig->getInstanceId(),
            'app_capabilities' => ['deliveries', 'pickups', 'read_scope', 'source_events'],
        ], array_merge($this->instanceHeaders(), ['Content-Type' => 'application/json']));
    }

    /**
     * Tell Synaplan that files on this Nextcloud changed, moved, were deleted
     * or came back from the trash. Returns the list of copies to re-upload.
     *
     * @param list<array{id: string, event: string, version: string, path: string}> $events
     * @return array{applied: int, ignored: int, reupload: list<array{external_id: string, id: string}>}
     */
    public function reportSourceEvents(array $events): array
    {
        if ($events === [] || $this->synaplanConfig->getLinkInstanceId() === '') {
            return ['applied' => 0, 'ignored' => 0, 'reupload' => []];
        }
        $decoded = $this->request(
            'POST',
            '/api/v1/platform-links/sources/events',
            ['events' => $events],
            array_merge($this->instanceHeaders(), ['Content-Type' => 'application/json']),
            true,
            true,
        );
        if (isset($decoded['status_code']) && (int) $decoded['status_code'] >= 400) {
            return ['applied' => 0, 'ignored' => 0, 'reupload' => []];
        }
        $reupload = [];
        foreach (is_array($decoded['reupload'] ?? null) ? $decoded['reupload'] : [] as $item) {
            if (is_array($item) && is_string($item['external_id'] ?? null) && is_string($item['id'] ?? null)) {
                $reupload[] = ['external_id' => $item['external_id'], 'id' => $item['id']];
            }
        }

        return [
            'applied' => (int) ($decoded['applied'] ?? 0),
            'ignored' => (int) ($decoded['ignored'] ?? 0),
            'reupload' => $reupload,
        ];
    }

    /**
     * Core capabilities from instances/self. Empty when Synaplan cannot be read.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        try {
            $decoded = $this->request('GET', '/api/v1/platform-links/instances/self', null, $this->instanceHeaders());
        } catch (\Throwable) {
            return [];
        }
        $capabilities = $decoded['capabilities'] ?? [];
        if (!is_array($capabilities)) {
            return [];
        }

        return array_values(array_filter($capabilities, 'is_string'));
    }

    /**
     * Bring one provisioned person's legacy identity row into the canonical shape.
     * Returns the HTTP status. 200 is success, including a repeated call.
     */
    public function adoptIdentity(string $uid, string $userKey): int
    {
        $result = $this->request(
            'POST',
            '/api/v1/platform-links/identities/adopt',
            ['external_id' => $uid],
            array_merge($this->instanceHeaders(), [
                'Content-Type' => 'application/json',
                'X-API-Key' => $userKey,
            ]),
            false,
            true,
        );

        return (int) ($result['status_code'] ?? 200);
    }

    /**
     * Bring a provisioned person's legacy row into the canonical shape right
     * before the Nextcloud user is deleted, so lifecycle(deleted) can find it
     * even when the background job has not run yet.
     */
    public function adoptBeforeDelete(string $uid, string $userKey): void
    {
        if ($uid === '' || $userKey === ''
            || $this->synaplanConfig->getLinkInstanceId() === ''
            || $this->decryptSecret() === '') {
            return;
        }
        try {
            $status = $this->adoptIdentity($uid, $userKey);
            if ($status === 403) {
                $url = $this->defaultDeliveryUrl();
                if ($url !== '') {
                    $this->announceDelivery($url);
                }
                $this->adoptIdentity($uid, $userKey);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not adopt {uid} before deletion: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A person was deleted, disabled or enabled here. Best-effort: a Synaplan
     * failure is logged and never thrown, so Nextcloud's own user change proceeds.
     * Does nothing until this Nextcloud is registered.
     */
    public function reportLifecycle(string $externalId, string $event): void
    {
        if ($externalId === '' || $this->synaplanConfig->getLinkInstanceId() === '') {
            return;
        }
        try {
            $this->request('POST', '/api/v1/platform-links/lifecycle', [
                'external_id' => $externalId,
                'event' => $event,
            ], array_merge($this->instanceHeaders(), ['Content-Type' => 'application/json']));
        } catch (\Throwable $e) {
            $this->logger->warning('Could not tell Synaplan that {uid} was {event}: {message}', [
                'app' => Application::APP_ID,
                'uid' => $externalId,
                'event' => $event,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function notifyKeys(): array
    {
        $decoded = $this->request('GET', '/api/v1/platform-links/instances/self', null, $this->instanceHeaders());
        $keys = $decoded['notify_keys'] ?? [];

        return is_array($keys) ? array_values(array_filter($keys, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function claimDelivery(string $id): array
    {
        return $this->request(
            'POST',
            '/api/v1/platform-links/deliveries/' . rawurlencode($id) . '/claim',
            null,
            $this->instanceHeaders(),
            false,
            true,
        );
    }

    public function downloadDelivery(string $downloadPath): ?string
    {
        if (!str_starts_with($downloadPath, '/api/') || str_contains($downloadPath, '..') || str_contains($downloadPath, '://')) {
            return null;
        }
        $client = $this->clientService->newClient();
        try {
            $response = $client->get($this->synaplanConfig->getBaseUrl() . $downloadPath, [
                'headers' => $this->instanceHeaders(),
                'timeout' => 30,
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not download a Synaplan copy: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
        if ((int) $response->getStatusCode() !== 200) {
            return null;
        }

        return $response->getBody();
    }

    /**
     * @return array<string, mixed>
     */
    public function claimPickup(string $id): array
    {
        return $this->request(
            'POST',
            '/api/v1/platform-links/pickups/' . rawurlencode($id) . '/claim',
            null,
            $this->instanceHeaders(),
            false,
            true,
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function submitPickupListing(string $id, array $items): array
    {
        return $this->request(
            'POST',
            '/api/v1/platform-links/pickups/' . rawurlencode($id) . '/listing',
            ['items' => $items],
            array_merge($this->instanceHeaders(), ['Content-Type' => 'application/json']),
            false,
            true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadPickupFile(
        string $id,
        string $remoteId,
        string $version,
        string $bytes,
        string $name,
        string $mime,
        int $timeoutSeconds = 120,
    ): array {
        $client = $this->clientService->newClient();
        $path = '/api/v1/platform-links/pickups/' . rawurlencode($id) . '/files';
        $response = $client->post(
            $this->synaplanConfig->getBaseUrl() . $path,
            [
                'headers' => $this->instanceHeaders(),
                'multipart' => [
                    ['name' => 'id', 'contents' => $remoteId],
                    ['name' => 'version', 'contents' => $version],
                    ['name' => 'file', 'contents' => $bytes, 'filename' => $name, 'headers' => ['Content-Type' => $mime]],
                ],
                'timeout' => max(1, $timeoutSeconds),
                'http_errors' => false,
                'nextcloud' => ['allow_local_address' => true],
            ],
        );
        $decoded = json_decode($response->getBody(), true);

        return ['status_code' => (int) $response->getStatusCode()] + (is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $result
     */
    public function reportPickupResult(string $id, array $result): void
    {
        $this->request(
            'POST',
            '/api/v1/platform-links/pickups/' . rawurlencode($id) . '/result',
            $result,
            array_merge($this->instanceHeaders(), ['Content-Type' => 'application/json']),
            false,
            true,
        );
    }

    /**
     * True when Synaplan took the setting. Its copy is what a run checks
     * before asking, so a false answer has to be retried, not dropped.
     *
     * @param list<array{id: string, path: string}> $folders
     */
    public function reportReadScope(string $uid, string $mode, array $folders): bool
    {
        $result = $this->request(
            'PUT',
            '/api/v1/platform-links/read-scope',
            ['external_id' => $uid, 'mode' => $mode, 'folders' => $folders],
            array_merge($this->instanceHeaders(), ['Content-Type' => 'application/json']),
            false,
            true,
        );

        return !isset($result['status_code']) || (int) $result['status_code'] === 200;
    }

    /**
     * @param array<string, mixed> $result
     */
    public function reportDelivery(string $id, array $result): void
    {
        $this->request(
            'POST',
            '/api/v1/platform-links/deliveries/' . rawurlencode($id) . '/result',
            $result,
            array_merge($this->instanceHeaders(), ['Content-Type' => 'application/json']),
            false,
            true,
        );
    }

    public function forgetRegistration(): void
    {
        $this->config->deleteAppValue(Application::APP_ID, 'link_instance_id');
        $this->config->deleteAppValue(Application::APP_ID, 'link_instance_secret');
    }

    /**
     * Exchange a one-time link code for a scoped per-user API key.
     *
     * @return array{api_key: array<string, mixed>, user: array<string, mixed>, link_id?: int|string}
     */
    public function exchange(string $code): array
    {
        $instanceId = $this->synaplanConfig->getLinkInstanceId();
        $secret = $this->decryptSecret();
        if ($instanceId === '' || $secret === '') {
            throw new PlatformLinkException(
                'This Nextcloud is not registered with Synaplan.',
                PlatformLinkException::CODE_INSTANCE_PENDING
            );
        }

        try {
            $decoded = $this->request('POST', '/api/v1/platform-links/exchange', [
                'instance_id' => $instanceId,
                'instance_secret' => $secret,
                'code' => $code,
            ], [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);
        } catch (PlatformLinkException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PlatformLinkException(
                'Could not finish connecting this account.',
                $this->mapHttpError($e)
            );
        }

        $key = (string) ($decoded['api_key']['key'] ?? '');
        if ($key === '') {
            throw new PlatformLinkException(
                'Could not finish connecting this account.',
                PlatformLinkException::CODE_EXCHANGE
            );
        }

        return $decoded;
    }

    public function disconnect(IUser $user): void
    {
        $uid = $user->getUID();
        $this->revokeRemoteKey($uid);
        $this->userAccounts->deactivateUser($uid);
    }

    /**
     * Best-effort cleanup for user deletion / admin deactivate.
     * Interactive disconnect must use {@see disconnect()} so a failed
     * revocation keeps the local key for a retry.
     */
    public function disconnectUid(string $uid): void
    {
        try {
            $this->revokeRemoteKey($uid);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not revoke linked Synaplan key for {uid}: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);
        }

        $this->userAccounts->deactivateUser($uid);
    }

    /**
     * @throws PlatformLinkException when the remote key is still valid and
     *                               could not be revoked
     */
    private function revokeRemoteKey(string $uid): void
    {
        $keyId = $this->userAccounts->getStoredApiKeyId($uid);
        $key = $this->userAccounts->getStoredApiKey($uid);
        if ($keyId === '' || $key === '') {
            return;
        }

        $this->request('DELETE', '/api/v1/apikeys/' . rawurlencode($keyId), null, [
            'Accept' => 'application/json',
            'X-API-Key' => $key,
        ], true);
    }

    /**
     * @return array<string, string>
     */
    private function instanceHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Instance-Id' => $this->synaplanConfig->getLinkInstanceId(),
            'X-Instance-Secret' => $this->decryptSecret(),
        ];
    }

    private function decryptSecret(): string
    {
        $cipher = $this->synaplanConfig->getLinkInstanceSecretCipher();
        if ($cipher === '') {
            return '';
        }
        try {
            return $this->crypto->decrypt($cipher);
        } catch (\Throwable) {
            return $cipher;
        }
    }

    public function publicHostFromUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? 'localhost');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @param bool $keepErrors return the decoded body of a 4xx instead of throwing,
     *                         with `status_code` set, so a refused claim can be told apart
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body, array $headers, bool $notFoundOk = false, bool $keepErrors = false): array
    {
        $client = $this->clientService->newClient();
        $url = $this->synaplanConfig->getBaseUrl() . $path;
        $options = [
            'headers' => $headers,
            'timeout' => 30,
            // Docker / localhost Synaplan is a private address. The integration
            // is supposed to reach it; Nextcloud's SSRF guard would otherwise
            // reject the exchange after the user already confirmed.
            'nextcloud' => [
                'allow_local_address' => true,
            ],
        ];
        if ($keepErrors) {
            // A refused claim is an answer (409, 410), not a transport failure.
            $options['http_errors'] = false;
        }
        if ($body !== null) {
            $options['body'] = json_encode($body);
        }

        try {
            $response = match (strtoupper($method)) {
                'POST' => $client->post($url, $options),
                'PUT' => $client->put($url, $options),
                'PATCH' => $client->patch($url, $options),
                'DELETE' => $client->delete($url, $options),
                default => $client->get($url, $options),
            };
        } catch (\Throwable $e) {
            $this->logger->warning('Synaplan platform-link request failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
            $code = $this->mapHttpError($e);
            throw new PlatformLinkException('Synaplan request failed.', $code);
        }

        $status = (int) $response->getStatusCode();
        $decoded = json_decode($response->getBody(), true);
        if ($keepErrors && $status >= 400) {
            return ['status_code' => $status] + (is_array($decoded) ? $decoded : []);
        }
        if ($status === 404) {
            if ($notFoundOk) {
                return [];
            }
            throw new PlatformLinkException(
                'Synaplan is not accepting platform connections yet.',
                PlatformLinkException::CODE_INSTANCE_PENDING
            );
        }
        if ($status === 403) {
            throw new PlatformLinkException(
                'This Nextcloud is waiting for approval.',
                PlatformLinkException::CODE_INSTANCE_PENDING
            );
        }
        if ($status >= 400) {
            throw new PlatformLinkException(
                'Could not finish connecting this account.',
                PlatformLinkException::CODE_EXCHANGE
            );
        }

        if (!is_array($decoded)) {
            throw new PlatformLinkException(
                'Invalid JSON response from Synaplan.',
                PlatformLinkException::CODE_EXCHANGE
            );
        }

        return $decoded;
    }

    private function mapHttpError(\Throwable $e): string
    {
        $status = $this->httpStatus($e);
        if ($status === 403 || $status === 404) {
            return PlatformLinkException::CODE_INSTANCE_PENDING;
        }

        return PlatformLinkException::CODE_EXCHANGE;
    }

    private function httpStatus(\Throwable $e): ?int
    {
        if ((int) $e->getCode() >= 400 && (int) $e->getCode() < 600) {
            return (int) $e->getCode();
        }
        if (method_exists($e, 'getResponse')) {
            $response = $e->getResponse();
            if (is_object($response) && method_exists($response, 'getStatusCode')) {
                return (int) $response->getStatusCode();
            }
        }
        if (preg_match('/\b(403|404|401|400)\b/', $e->getMessage(), $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
