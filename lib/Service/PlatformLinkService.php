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
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body, array $headers, bool $notFoundOk = false): array
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
        if ($body !== null) {
            $options['body'] = json_encode($body);
        }

        try {
            $response = match (strtoupper($method)) {
                'POST' => $client->post($url, $options),
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

        if (method_exists($response, 'getStatusCode')) {
            $status = (int) $response->getStatusCode();
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
        }

        $decoded = json_decode($response->getBody(), true);
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
