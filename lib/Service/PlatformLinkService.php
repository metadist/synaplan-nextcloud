<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Exception\PlatformLinkException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * This Nextcloud as an OAuth2 client of Synaplan. Registration, client
 * configuration and the authorization-code handshake go through /oauth/*.
 * A person's API calls use that person's access token, never a secret of
 * the cloud.
 *
 * Talks via IClientService (never SynaplanClient) so a 401 on a user token
 * cannot re-enter registration or the token endpoint.
 */
class PlatformLinkService
{
    private const APP_CAPABILITIES = ['deliveries', 'pickups', 'read_scope', 'source_events'];

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private SynaplanConfig $synaplanConfig,
        private UserAccountService $userAccounts,
        private LoggerInterface $logger,
        private CloudClientKey $cloudKey,
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

    /**
     * Push the authorization request and return the browser URL plus the
     * PKCE verifier the callback sends to the token endpoint.
     *
     * @return array{url: string, code_verifier: string}
     */
    public function beginConnect(string $externalId, string $state): array
    {
        $verifier = bin2hex(random_bytes(32));
        $scope = 'chat files rag cloud:watch';
        if ($this->synaplanConfig->isMemoriesEnabled()) {
            $scope .= ' memories';
        }
        $pushed = $this->form('/oauth/par', [
            'response_type' => 'code',
            'redirect_uri' => $this->callbackUrl(),
            'state' => $state,
            'code_challenge' => self::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'external_id' => $externalId,
            'scope' => $scope,
        ]);
        $requestUri = (string) ($pushed['request_uri'] ?? '');
        if ($requestUri === '') {
            throw new PlatformLinkException(
                'Could not start connecting this account.',
                PlatformLinkException::CODE_EXCHANGE
            );
        }

        return [
            'url' => $this->synaplanConfig->getPublicBaseUrl() . '/oauth/authorize?' . http_build_query([
                'client_id' => $this->synaplanConfig->getLinkInstanceId(),
                'request_uri' => $requestUri,
            ]),
            'code_verifier' => $verifier,
        ];
    }

    /**
     * Register this Nextcloud as an OAuth2 client of Synaplan.
     *
     * The private key is created here and never leaves this server. Whether
     * the client is active at once is Synaplan's registration policy.
     *
     * @return array{instance_id: string, status: string, host: string}
     */
    public function registerInstance(?string $adminKey = null): array
    {
        $callback = $this->callbackUrl();
        $host = $this->publicHostFromUrl($callback);
        $metadata = $this->clientMetadata($callback, $this->defaultDeliveryUrl());
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        // An initial access token approves the client at once. An API key is
        // not one, and a wrong bearer is rate-limited, so it is not sent.
        if (is_string($adminKey) && $adminKey !== '' && !str_starts_with($adminKey, 'sk_')) {
            $headers['Authorization'] = 'Bearer ' . $adminKey;
        }
        $decoded = $this->request('POST', '/oauth/register', $metadata, $headers, false, true);
        if (($decoded['status_code'] ?? 0) >= 400 && isset($metadata['delivery_url'])) {
            unset($metadata['delivery_url']);
            $decoded = $this->request('POST', '/oauth/register', $metadata, $headers, false, true);
        }
        if (($decoded['status_code'] ?? 0) >= 400) {
            throw new PlatformLinkException(
                'Synaplan is not accepting platform connections yet.',
                PlatformLinkException::CODE_INSTANCE_PENDING
            );
        }

        $instanceId = (string) ($decoded['client_id'] ?? '');
        if ($instanceId === '') {
            throw new PlatformLinkException(
                'Registration response missing credentials.',
                PlatformLinkException::CODE_EXCHANGE
            );
        }

        $this->config->setAppValue(Application::APP_ID, 'link_instance_id', $instanceId);
        $status = (string) ($decoded['status'] ?? 'pending');
        $this->logger->info('Registered Nextcloud instance with Synaplan ({status})', [
            'app' => Application::APP_ID,
            'status' => $status,
            'instance_id' => $instanceId,
        ]);

        return [
            'instance_id' => $instanceId,
            'status' => $status,
            'host' => $host,
        ];
    }

    /**
     * @return array{status: string, host: string, client: string}|array{error: string}
     */
    public function instanceStatus(): array
    {
        $instanceId = $this->synaplanConfig->getLinkInstanceId();
        if ($instanceId === '') {
            return ['error' => 'not_registered'];
        }

        try {
            $decoded = $this->request(
                'GET',
                '/oauth/register/' . rawurlencode($instanceId),
                null,
                $this->clientHeaders('/oauth/register/' . rawurlencode($instanceId)),
            );

            return [
                'status' => (string) ($decoded['status'] ?? ''),
                'host' => (string) ($decoded['client_uri'] ?? ''),
                'client' => (string) ($decoded['software_id'] ?? 'nextcloud'),
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
        $clientId = $this->synaplanConfig->getLinkInstanceId();
        $this->request(
            'PUT',
            '/oauth/register/' . rawurlencode($clientId),
            $this->clientMetadata($this->callbackUrl(), $deliveryUrl),
            $this->clientHeaders('/oauth/register/' . rawurlencode($clientId)),
        );
    }

    /**
     * Tell Synaplan that files on this Nextcloud changed, moved, were deleted
     * or came back from the trash. Returns the list of copies to re-upload.
     *
     * @param list<array{id: string, event: string, version: string, path: string}> $events
     * @return array{applied: int, ignored: int, reupload: list<array{external_id: string, id: string}>, retry?: bool}
     */
    public function reportSourceEvents(array $events): array
    {
        if ($events === [] || $this->synaplanConfig->getLinkInstanceId() === '') {
            return ['applied' => 0, 'ignored' => 0, 'reupload' => []];
        }
        try {
            $decoded = $this->request(
                'POST',
                '/api/v1/platform-links/sources/events',
                ['events' => $events],
                array_merge($this->instanceHeaders(), ['Content-Type' => 'application/json']),
                true,
                true,
            );
        } catch (\Throwable) {
            return ['applied' => 0, 'ignored' => 0, 'reupload' => [], 'retry' => true];
        }
        $status = (int) ($decoded['status_code'] ?? 200);
        if ($status === 429 || $status >= 500) {
            return ['applied' => 0, 'ignored' => 0, 'reupload' => [], 'retry' => true];
        }
        if ($status >= 400) {
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
     * Core capabilities from the discovery document. Empty when Synaplan cannot be read.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        try {
            $decoded = $this->request('GET', '/.well-known/synaplan-configuration', null, [
                'Accept' => 'application/json',
            ]);
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
     * A person was deleted, disabled or enabled here. Best-effort: a Synaplan
     * failure is logged and never thrown, so Nextcloud's own user change proceeds.
     * Does nothing until this Nextcloud is registered.
     */
    public function reportLifecycle(string $externalId, string $event): void
    {
        $clientId = $this->synaplanConfig->getLinkInstanceId();
        if ($externalId === '' || $clientId === '') {
            return;
        }
        try {
            $this->request('POST', '/oauth/register/' . rawurlencode($clientId) . '/lifecycle', [
                'external_id' => $externalId,
                'event' => $event,
            ], array_merge(
                $this->clientHeaders('/oauth/register/' . rawurlencode($clientId) . '/lifecycle'),
                ['Content-Type' => 'application/json'],
            ));
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
        $decoded = $this->request('GET', '/.well-known/jwks.json', null, [
            'Accept' => 'application/json',
        ]);
        $mapped = [];
        foreach (is_array($decoded['keys'] ?? null) ? $decoded['keys'] : [] as $key) {
            if (!is_array($key) || ($key['kty'] ?? '') !== 'OKP' || ($key['crv'] ?? '') !== 'Ed25519') {
                continue;
            }
            $x = $key['x'] ?? null;
            if (!is_string($x) || $x === '') {
                continue;
            }
            $mapped[] = [
                'publicKey' => 'ed25519:' . $x,
                'kid' => is_string($key['kid'] ?? null) ? $key['kid'] : '',
            ];
        }

        return $mapped;
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
     * Exchange the authorization code for this person's tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, user: array{id: int|string, email: string}}
     */
    public function exchange(string $code, string $verifier): array
    {
        if ($this->synaplanConfig->getLinkInstanceId() === '') {
            throw new PlatformLinkException(
                'This Nextcloud is not registered with Synaplan.',
                PlatformLinkException::CODE_INSTANCE_PENDING
            );
        }

        try {
            $decoded = $this->form('/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->callbackUrl(),
                'code_verifier' => $verifier,
            ]);
        } catch (PlatformLinkException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PlatformLinkException(
                'Could not finish connecting this account.',
                $this->mapHttpError($e)
            );
        }

        $access = (string) ($decoded['access_token'] ?? '');
        $refresh = (string) ($decoded['refresh_token'] ?? '');
        if ($access === '' || $refresh === '') {
            throw new PlatformLinkException(
                'Could not finish connecting this account.',
                PlatformLinkException::CODE_EXCHANGE
            );
        }
        $me = $this->request('GET', '/api/v1/auth/me', null, SynaplanCredential::headers($access));
        $user = is_array($me['user'] ?? null) ? $me['user'] : [];

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => (int) ($decoded['expires_in'] ?? 0),
            'user' => [
                'id' => is_scalar($user['id'] ?? null) ? $user['id'] : '',
                'email' => (string) ($user['email'] ?? ''),
            ],
        ];
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
        $refresh = $this->userAccounts->getRefreshToken($uid);
        if ($refresh !== '') {
            $this->form('/oauth/revoke', ['token' => $refresh]);

            return;
        }

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
     * Bearer client assertion for one registration endpoint. Reading or
     * changing the registration does not accept the issuer as audience.
     *
     * @return array<string, string>
     */
    private function clientHeaders(string $path): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->cloudKey->assertion($path),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientMetadata(string $redirectUri, string $deliveryUrl): array
    {
        $metadata = [
            'software_id' => 'nextcloud',
            'client_uri' => $this->publicHostFromUrl($redirectUri),
            'redirect_uris' => [$redirectUri],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'private_key_jwt',
            'jwks' => ['keys' => [$this->cloudKey->publicJwk()]],
            'app_capabilities' => self::APP_CAPABILITIES,
            'server_id' => $this->synaplanConfig->getInstanceId(),
        ];
        if ($deliveryUrl !== '') {
            $metadata['delivery_url'] = $deliveryUrl;
        }

        return $metadata;
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function form(string $path, array $fields): array
    {
        $fields['client_assertion_type'] = CloudClientKey::ASSERTION_TYPE;
        $fields['client_assertion'] = $this->cloudKey->assertion($path);

        return $this->dispatch('POST', $path, null, [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], false, true, $fields);
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * The signed-in person's access token, when a request is handling one.
     * Cloud routes attribute the caller from that token.
     *
     * @return array<string, string>
     */
    private function instanceHeaders(): array
    {
        $key = $this->userAccounts->getCurrentUserApiKey() ?? '';

        return SynaplanCredential::headers($key);
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
     * @param array<string, string>|null $form sent as application/x-www-form-urlencoded instead of JSON
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body, array $headers, bool $notFoundOk = false, bool $keepErrors = false, ?array $form = null): array
    {
        return $this->dispatch($method, $path, $body, $headers, $notFoundOk, $keepErrors, $form);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @param array<string, string>|null $form
     * @return array<string, mixed>
     */
    private function dispatch(string $method, string $path, ?array $body, array $headers, bool $notFoundOk = false, bool $keepErrors = false, ?array $form = null): array
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
        if ($form !== null) {
            $options['body'] = http_build_query($form);
        } elseif ($body !== null) {
            $options['headers']['Content-Type'] ??= 'application/json';
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
            if ($status >= 200 && $status < 300) {
                return [];
            }
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
