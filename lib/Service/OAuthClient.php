<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Exception\PlatformLinkException;
use OCP\Config\IUserConfig;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * This Nextcloud as an OAuth2 client of Synaplan.
 *
 * The installation registers itself (RFC 7591) with a key pair it made here
 * and proves itself with short signed assertions (private_key_jwt): Synaplan
 * keeps only the public key, and no secret ever crosses the wire. A person
 * connects once (authorization code flow with PKCE); their rotating refresh
 * token is kept encrypted in their user config, and a short-lived access
 * token is minted from it whenever the app acts for them. Disconnecting, or
 * the account going away here, revokes the grant (RFC 7009).
 */
class OAuthClient
{
    public const SCOPE = 'chat files rag cloud:watch';

    private const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
    private const KEY = 'oauth_private_key';
    private const KID = 'oauth_kid';
    private const CLIENT_ID = 'link_instance_id';
    private const REFRESH = 'synaplan_oauth_refresh';
    private const ACCESS = 'synaplan_oauth_access';
    private const EXPIRES = 'synaplan_oauth_expires';

    public function __construct(
        private IClientService $clientService,
        private IAppConfig $appConfig,
        private IConfig $config,
        private IUserConfig $userConfig,
        private SynaplanConfig $synaplanConfig,
        private LoggerInterface $logger,
    ) {
    }

    public function clientId(): string
    {
        return (string) $this->config->getAppValue(Application::APP_ID, self::CLIENT_ID, '');
    }

    public function isRegistered(): bool
    {
        return $this->clientId() !== '' && $this->appConfig->getValueString(Application::APP_ID, self::KEY) !== '';
    }

    /**
     * Register this Nextcloud with Synaplan. The registration token (the
     * initial access token a Synaplan administrator hands out) makes the
     * client active at once; without it Synaplan may hold it for approval.
     *
     * @param array{redirect_uri: string, client_uri: string, delivery_url: string, capabilities: list<string>, server_id: string, version: string} $metadata
     *
     * @return array<string, mixed> Synaplan's answer
     */
    public function register(array $metadata, string $registrationToken): array
    {
        $this->newKey();
        $headers = $registrationToken !== '' ? ['Authorization' => 'Bearer ' . $registrationToken] : [];
        $answer = $this->json('POST', '/oauth/register', [
            'software_id' => 'nextcloud',
            'software_version' => $metadata['version'],
            'client_uri' => $metadata['client_uri'],
            'redirect_uris' => [$metadata['redirect_uri']],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_method' => 'private_key_jwt',
            'jwks' => ['keys' => [$this->publicJwk()]],
            'delivery_url' => $metadata['delivery_url'],
            'app_capabilities' => $metadata['capabilities'],
            'server_id' => $metadata['server_id'],
        ], $headers);
        $clientId = $answer['client_id'] ?? null;
        if (!is_string($clientId) || $clientId === '') {
            throw new PlatformLinkException('Synaplan did not register this Nextcloud.', PlatformLinkException::CODE_EXCHANGE);
        }
        $this->config->setAppValue(Application::APP_ID, self::CLIENT_ID, $clientId);

        return $answer;
    }

    /**
     * The registration as Synaplan has it (RFC 7592): status, delivery
     * address, reachability.
     *
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        return $this->json('GET', '/oauth/register/' . rawurlencode($this->clientId()), null, $this->assertionHeader());
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function updateConfiguration(array $changes): array
    {
        return $this->json('PUT', '/oauth/register/' . rawurlencode($this->clientId()), $changes, $this->assertionHeader());
    }

    /**
     * Leave Synaplan: every grant of this client ends there, and the key and
     * client id go here.
     */
    public function unregister(): void
    {
        if ($this->isRegistered()) {
            try {
                $this->json('DELETE', '/oauth/register/' . rawurlencode($this->clientId()), null, $this->assertionHeader());
            } catch (\Throwable $e) {
                $this->logger->warning('Could not unregister from Synaplan: {message}', ['app' => Application::APP_ID, 'message' => $e->getMessage()]);
            }
        }
        $this->config->deleteAppValue(Application::APP_ID, self::CLIENT_ID);
        $this->appConfig->deleteKey(Application::APP_ID, self::KEY);
        $this->appConfig->deleteKey(Application::APP_ID, self::KID);
    }

    /**
     * Where the person's browser goes to allow this Nextcloud. Keep the
     * verifier until the code comes back.
     *
     * @return array{url: string, verifier: string}
     */
    public function authorizeUrl(string $uid, string $state, string $redirectUri, bool $withMemories): array
    {
        $verifier = self::b64(random_bytes(32));
        $scope = self::SCOPE . ($withMemories ? ' memories' : '');

        return [
            'url' => $this->issuer() . '/oauth/authorize?' . http_build_query([
                'response_type' => 'code',
                'client_id' => $this->clientId(),
                'redirect_uri' => $redirectUri,
                'scope' => $scope,
                'state' => $state,
                'code_challenge' => self::b64(hash('sha256', $verifier, true)),
                'code_challenge_method' => 'S256',
                'external_id' => $uid,
            ]),
            'verifier' => $verifier,
        ];
    }

    /**
     * Turn the code into the person's grant and keep it.
     */
    public function connect(string $uid, string $code, string $verifier, string $redirectUri): void
    {
        $this->remember($uid, $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ]));
    }

    /**
     * @phpstan-impure accessToken() forgets a grant Synaplan ended
     */
    public function isConnected(string $uid): bool
    {
        return $this->secret($uid, self::REFRESH) !== '';
    }

    /**
     * A current access token for the person, refreshed when it ran out; ''
     * when they are not connected or Synaplan ended the grant (it is then
     * forgotten here).
     */
    public function accessToken(string $uid): string
    {
        $access = $this->secret($uid, self::ACCESS);
        $expires = (int) $this->userConfig->getValueString($uid, Application::APP_ID, self::EXPIRES);
        if ($access !== '' && $expires > time() + 30) {
            return $access;
        }
        $refresh = $this->secret($uid, self::REFRESH);
        if ($refresh === '') {
            return '';
        }
        try {
            $tokens = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        } catch (PlatformLinkException $e) {
            if ($e->getErrorCode() === PlatformLinkException::CODE_GRANT_ENDED) {
                $this->forget($uid);
            }

            return '';
        }
        $this->remember($uid, $tokens);

        return (string) ($tokens['access_token'] ?? '');
    }

    /**
     * End the person's grant at Synaplan (which ends their link there) and
     * forget it here.
     */
    public function revoke(string $uid): void
    {
        $refresh = $this->secret($uid, self::REFRESH);
        if ($refresh !== '' && $this->isRegistered()) {
            try {
                $this->form('/oauth/revoke', ['token' => $refresh] + $this->assertionFields());
            } catch (\Throwable $e) {
                $this->logger->warning('Could not revoke the Synaplan grant of {uid}: {message}', [
                    'app' => Application::APP_ID,
                    'uid' => $uid,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        $this->forget($uid);
    }

    public function forget(string $uid): void
    {
        foreach ([self::REFRESH, self::ACCESS, self::EXPIRES] as $key) {
            $this->userConfig->deleteUserConfig($uid, Application::APP_ID, $key);
        }
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function token(array $params): array
    {
        return $this->form('/oauth/token', $params + ['client_id' => $this->clientId()] + $this->assertionFields());
    }

    /**
     * @param array<string, mixed> $tokens
     */
    private function remember(string $uid, array $tokens): void
    {
        $access = (string) ($tokens['access_token'] ?? '');
        $refresh = (string) ($tokens['refresh_token'] ?? '');
        if ($access === '' || $refresh === '') {
            throw new PlatformLinkException('Synaplan answered without tokens.', PlatformLinkException::CODE_EXCHANGE);
        }
        $this->userConfig->setValueString($uid, Application::APP_ID, self::REFRESH, $refresh, flags: IUserConfig::FLAG_SENSITIVE);
        $this->userConfig->setValueString($uid, Application::APP_ID, self::ACCESS, $access, flags: IUserConfig::FLAG_SENSITIVE);
        $this->userConfig->setValueString($uid, Application::APP_ID, self::EXPIRES, (string) (time() + (int) ($tokens['expires_in'] ?? 0)));
    }

    private function secret(string $uid, string $key): string
    {
        $value = $this->userConfig->getValueString($uid, Application::APP_ID, $key);

        return str_starts_with($value, '$UserConfigEncryption$') ? '' : $value;
    }

    /**
     * @return array<string, string>
     */
    private function assertionHeader(): array
    {
        return ['Authorization' => 'Bearer ' . $this->assertion()];
    }

    /**
     * @return array{client_assertion_type: string, client_assertion: string}
     */
    private function assertionFields(): array
    {
        return ['client_assertion_type' => self::ASSERTION_TYPE, 'client_assertion' => $this->assertion()];
    }

    /**
     * A one-minute client assertion, addressed to Synaplan's issuer.
     */
    public function assertion(): string
    {
        $clientId = $this->clientId();
        $header = self::b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->appConfig->getValueString(Application::APP_ID, self::KID)]));
        $payload = self::b64((string) json_encode([
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => $this->issuer(),
            'jti' => bin2hex(random_bytes(16)),
            'iat' => time(),
            'exp' => time() + 60,
        ], JSON_UNESCAPED_SLASHES));
        $private = $this->appConfig->getValueString(Application::APP_ID, self::KEY);
        if ($private === '' || !openssl_sign($header . '.' . $payload, $signature, $private, OPENSSL_ALGO_SHA256)) {
            throw new PlatformLinkException('This Nextcloud has no Synaplan client key.', PlatformLinkException::CODE_NOT_REGISTERED);
        }

        return $header . '.' . $payload . '.' . self::b64((string) $signature);
    }

    private function issuer(): string
    {
        $issuer = (string) $this->config->getAppValue(Application::APP_ID, NotifyKeyCache::ISSUER, '');
        if ($issuer === '') {
            $configuration = $this->json('GET', '/.well-known/synaplan-configuration', null, []);
            $issuer = is_string($configuration['issuer'] ?? null) ? $configuration['issuer'] : '';
        }
        if ($issuer === '') {
            throw new PlatformLinkException('Synaplan did not say who it is (/.well-known/synaplan-configuration).', PlatformLinkException::CODE_EXCHANGE);
        }

        return rtrim($issuer, '/');
    }

    private function newKey(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || !openssl_pkey_export($key, $private)) {
            throw new PlatformLinkException('Could not create the Synaplan client key.', PlatformLinkException::CODE_EXCHANGE);
        }
        $this->appConfig->setValueString(Application::APP_ID, self::KEY, (string) $private, sensitive: true);
        $this->appConfig->setValueString(Application::APP_ID, self::KID, bin2hex(random_bytes(8)));
    }

    /**
     * @return array<string, string>
     */
    private function publicJwk(): array
    {
        $private = openssl_pkey_get_private($this->appConfig->getValueString(Application::APP_ID, self::KEY));
        $details = $private === false ? false : openssl_pkey_get_details($private);
        if (!is_array($details) || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new PlatformLinkException('The Synaplan client key is unreadable.', PlatformLinkException::CODE_EXCHANGE);
        }

        return [
            'kty' => 'RSA',
            'kid' => $this->appConfig->getValueString(Application::APP_ID, self::KID),
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => self::b64($details['rsa']['n']),
            'e' => self::b64($details['rsa']['e']),
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function json(string $method, string $path, ?array $body, array $headers): array
    {
        $options = [
            'headers' => $headers + ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
        ];
        if ($body !== null) {
            $options['body'] = json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        return $this->send($method, $path, $options);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array<string, mixed>
     */
    private function form(string $path, array $fields): array
    {
        return $this->send('POST', $path, [
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query($fields),
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $options): array
    {
        $options += [
            'timeout' => 30,
            'http_errors' => false,
            // Docker / localhost Synaplan is a private address the integration
            // is supposed to reach.
            'nextcloud' => ['allow_local_address' => true],
        ];
        try {
            $response = $this->clientService->newClient()->request($method, $this->synaplanConfig->getBaseUrl() . $path, $options);
        } catch (\Throwable $e) {
            throw new PlatformLinkException('Synaplan cannot be reached.', PlatformLinkException::CODE_UNREACHABLE);
        }
        $status = (int) $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        $body = is_array($decoded) ? $decoded : [];
        if ($status >= 400) {
            $error = is_string($body['error'] ?? null) ? $body['error'] : '';
            $code = in_array($error, ['invalid_grant', 'invalid_client'], true) || $status === 401
                ? PlatformLinkException::CODE_GRANT_ENDED
                : PlatformLinkException::CODE_EXCHANGE;
            throw new PlatformLinkException(
                is_string($body['error_description'] ?? null) ? $body['error_description'] : 'Synaplan refused the request (' . $status . ').',
                $code,
            );
        }

        return $body;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
