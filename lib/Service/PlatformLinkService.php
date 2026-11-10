<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Exception\PlatformLinkException;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * The calls this Nextcloud makes to Synaplan's platform-links API for one
 * person (copies, folder reads, read scope, source events), with that
 * person's own credential, and the registration's lifecycle.
 *
 * Talks via IClientService (never SynaplanClient) so a 401 on a person's
 * credential cannot re-enter connecting.
 */
class PlatformLinkService
{
    public const CAPABILITIES = ['deliveries', 'pickups', 'read_scope', 'source_events'];
    private const REGISTRATION_STATUS = 'link_registration_status';

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private OAuthClient $oauth,
        private IAppManager $appManager,
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
     * Tell Synaplan that files of one person changed, moved, were deleted or
     * came back from the trash, with that person's key. Synaplan applies them
     * to that person's copies only. Returns the list of copies to re-upload.
     *
     * @param list<array{id: string, event: string, version: string, path: string}> $events
     * @return array{applied: int, ignored: int, reupload: list<array{external_id: string, id: string}>, retry?: bool}
     */
    public function reportSourceEvents(array $events, string $uid): array
    {
        if ($events === [] || $this->synaplanConfig->getLinkInstanceId() === '' || !$this->canActFor($uid)) {
            return ['applied' => 0, 'ignored' => 0, 'reupload' => []];
        }
        try {
            $decoded = $this->request(
                'POST',
                '/api/v1/platform-links/sources/events',
                ['events' => $events],
                array_merge($this->personHeaders($uid), ['Content-Type' => 'application/json']),
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
     * The issuer Synaplan's notifications carry and the keys they are signed
     * with, from Synaplan's public configuration and JWK Set. Both are read at
     * the configured address; the issuer is Synaplan's public URL, which may
     * differ from it.
     *
     * @return array{issuer: string, keys: list<array{publicKey: string, kid: string}>}
     */
    public function notificationTrust(): array
    {
        $configuration = $this->request('GET', '/.well-known/synaplan-configuration', null, []);
        $jwks = $this->request('GET', '/.well-known/jwks.json', null, []);
        $keys = [];
        foreach (is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [] as $jwk) {
            if (is_array($jwk) && ($jwk['kty'] ?? null) === 'OKP' && ($jwk['crv'] ?? null) === 'Ed25519' && is_string($jwk['x'] ?? null)) {
                $keys[] = ['publicKey' => 'ed25519:' . $jwk['x'], 'kid' => is_string($jwk['kid'] ?? null) ? $jwk['kid'] : ''];
            }
        }

        return [
            'issuer' => is_string($configuration['issuer'] ?? null) ? $configuration['issuer'] : '',
            'keys' => $keys,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function claimDelivery(string $id, string $uid): array
    {
        return $this->request(
            'POST',
            '/api/v1/platform-links/deliveries/' . rawurlencode($id) . '/claim',
            null,
            $this->personHeaders($uid),
            false,
            true,
        );
    }

    public function downloadDelivery(string $downloadPath, string $uid): ?string
    {
        if (!str_starts_with($downloadPath, '/api/') || str_contains($downloadPath, '..') || str_contains($downloadPath, '://')) {
            return null;
        }
        $client = $this->clientService->newClient();
        try {
            $response = $client->get($this->synaplanConfig->getBaseUrl() . $downloadPath, [
                'headers' => $this->personHeaders($uid),
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
    public function claimPickup(string $id, string $uid): array
    {
        return $this->request(
            'POST',
            '/api/v1/platform-links/pickups/' . rawurlencode($id) . '/claim',
            null,
            $this->personHeaders($uid),
            false,
            true,
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function submitPickupListing(string $id, string $uid, array $items): array
    {
        return $this->request(
            'POST',
            '/api/v1/platform-links/pickups/' . rawurlencode($id) . '/listing',
            ['items' => $items],
            array_merge($this->personHeaders($uid), ['Content-Type' => 'application/json']),
            false,
            true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadPickupFile(
        string $id,
        string $uid,
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
                'headers' => $this->personHeaders($uid),
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
    public function reportPickupResult(string $id, string $uid, array $result): void
    {
        $this->request(
            'POST',
            '/api/v1/platform-links/pickups/' . rawurlencode($id) . '/result',
            $result,
            array_merge($this->personHeaders($uid), ['Content-Type' => 'application/json']),
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
        if (!$this->canActFor($uid)) {
            return false;
        }
        $result = $this->request(
            'PUT',
            '/api/v1/platform-links/read-scope',
            ['external_id' => $uid, 'mode' => $mode, 'folders' => $folders],
            array_merge($this->personHeaders($uid), ['Content-Type' => 'application/json']),
            false,
            true,
        );

        return !isset($result['status_code']) || (int) $result['status_code'] === 200;
    }

    /**
     * @param array<string, mixed> $result
     */
    public function reportDelivery(string $id, string $uid, array $result): void
    {
        $this->request(
            'POST',
            '/api/v1/platform-links/deliveries/' . rawurlencode($id) . '/result',
            $result,
            array_merge($this->personHeaders($uid), ['Content-Type' => 'application/json']),
            false,
            true,
        );
    }

    /**
     * Leave Synaplan: its registration of this Nextcloud ends, and with it
     * every person's grant.
     */
    public function forgetRegistration(): void
    {
        $this->oauth->unregister();
        $this->config->deleteAppValue(Application::APP_ID, 'link_instance_secret');
        $this->config->deleteAppValue(Application::APP_ID, self::REGISTRATION_STATUS);
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
     * A connected person's grant is revoked at Synaplan (which ends their
     * link there); a provisioned key is deleted with itself.
     *
     * @throws PlatformLinkException when the remote key is still valid and
     *                               could not be revoked
     */
    private function revokeRemoteKey(string $uid): void
    {
        if ($this->oauth->isConnected($uid)) {
            $this->oauth->revoke($uid);

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
     * Register this Nextcloud with Synaplan as an OAuth2 client, with its
     * address, the page people return to, where Synaplan notifies it and what
     * this app does.
     *
     * @return array{instance_id: string, status: string}
     */
    public function register(string $registrationToken): array
    {
        $answer = $this->oauth->register([
            'client_uri' => $this->urlGenerator->getAbsoluteURL('/'),
            'redirect_uri' => $this->callbackUrl(),
            'delivery_url' => $this->defaultDeliveryUrl(),
            'capabilities' => self::CAPABILITIES,
            'server_id' => $this->synaplanConfig->getInstanceId(),
            'version' => $this->appManager->getAppVersion(Application::APP_ID),
        ], $registrationToken);
        $status = is_string($answer['status'] ?? null) ? $answer['status'] : '';
        $this->config->setAppValue(Application::APP_ID, self::REGISTRATION_STATUS, $status);

        return [
            'instance_id' => $this->oauth->clientId(),
            'status' => $status,
        ];
    }

    /**
     * Whether Synaplan still holds this registration for its administrator's
     * approval. Asked again only while it was pending, so people are told
     * here instead of meeting an error page in Synaplan.
     */
    public function isWaitingForApproval(): bool
    {
        if ($this->config->getAppValue(Application::APP_ID, self::REGISTRATION_STATUS, '') !== 'pending') {
            return false;
        }
        try {
            $status = $this->registrationStatus()['status'] ?? '';
        } catch (\Throwable) {
            return true;
        }

        return $status === 'pending';
    }

    /**
     * The registration as Synaplan has it, or null when there is none.
     *
     * @return array{status: string, host: string, delivery_url: string, reachable: ?bool}|null
     */
    public function registrationStatus(): ?array
    {
        if (!$this->oauth->isRegistered()) {
            return null;
        }
        $answer = $this->oauth->configuration();
        $status = is_string($answer['status'] ?? null) ? $answer['status'] : '';
        if ($status !== '') {
            $this->config->setAppValue(Application::APP_ID, self::REGISTRATION_STATUS, $status);
        }

        return [
            'status' => $status,
            'host' => $this->publicHostFromUrl(is_string($answer['client_uri'] ?? null) ? $answer['client_uri'] : ''),
            'delivery_url' => is_string($answer['delivery_url'] ?? null) ? $answer['delivery_url'] : '',
            'reachable' => is_bool($answer['reachable'] ?? null) ? $answer['reachable'] : null,
        ];
    }

    /**
     * Tell Synaplan where to call, which server this is, and what this app
     * does (client configuration, RFC 7592).
     */
    public function announceDelivery(string $deliveryUrl): void
    {
        if (!$this->oauth->isRegistered()) {
            return;
        }
        $this->oauth->updateConfiguration([
            'delivery_url' => $deliveryUrl,
            'server_id' => $this->synaplanConfig->getInstanceId(),
            'app_capabilities' => self::CAPABILITIES,
            'software_version' => $this->appManager->getAppVersion(Application::APP_ID),
        ]);
    }

    /**
     * Tell Synaplan a person here was deleted, disabled or enabled again.
     * A failure is logged: the change here stands either way.
     */
    public function reportLifecycle(string $externalId, string $event): void
    {
        if ($externalId === '' || !$this->oauth->isRegistered()) {
            return;
        }
        try {
            $this->oauth->reportLifecycle($externalId, $event);
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
     * Who the person is in Synaplan, asked with their own credential.
     *
     * @return array{id?: int|string, email?: string}
     */
    public function whoAmI(string $uid): array
    {
        $me = $this->request('GET', '/api/v1/auth/me', null, $this->personHeaders($uid));
        $user = is_array($me['user'] ?? null) ? $me['user'] : [];

        return [
            'id' => is_int($user['id'] ?? null) || is_string($user['id'] ?? null) ? $user['id'] : '',
            'email' => is_string($user['email'] ?? null) ? $user['email'] : '',
        ];
    }

    /**
     * Whether this person can be acted for: their own Synaplan credential is held.
     */
    public function canActFor(string $uid): bool
    {
        return $uid !== '' && $this->userAccounts->getStoredApiKey($uid) !== '';
    }

    /**
     * Calls done for one person carry that person's own credential: their
     * access token, or a provisioned person's key. Synaplan takes the cloud
     * from it and checks that the copy, folder read or file belongs to that
     * person.
     *
     * @return array<string, string>
     */
    private function personHeaders(string $uid): array
    {
        $key = $uid !== '' ? $this->userAccounts->getStoredApiKey($uid) : '';
        if ($key === '') {
            throw new \RuntimeException('No Synaplan credential is held for this person.');
        }

        return ['Accept' => 'application/json'] + SynaplanCredential::header($key);
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
