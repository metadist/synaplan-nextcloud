<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Exception\PlatformLinkException;
use OCA\SynaplanIntegration\Service\OAuthClient;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCP\Config\IUserConfig;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class OAuthClientTest extends TestCase
{
    private static string $privateKey = '';

    /** @var array<string, string> */
    private array $app = [];
    /** @var array<string, string> */
    private array $user = [];
    /** @var array<string, bool> */
    private array $sensitive = [];
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];
    /** @var \Closure(string, string, array<string, mixed>): array{0: int, 1: array<string, mixed>} */
    private \Closure $respond;
    /** @var \Closure(string): void */
    private \Closure $onLock;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        self::$privateKey = (string) $pem;
    }

    protected function setUp(): void
    {
        $this->app = [];
        $this->user = [];
        $this->sensitive = [];
        $this->requests = [];
        $this->respond = static fn (): array => [200, []];
        $this->onLock = static function (): void {
        };
    }

    private function client(): OAuthClient
    {
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $this->app[$key] ?? $default
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value): void {
                $this->app[$key] = $value;
            }
        );
        $config->method('deleteAppValue')->willReturnCallback(
            function (string $app, string $key): void {
                unset($this->app[$key]);
            }
        );

        $appConfig = $this->createStub(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $this->app[$key] ?? $default
        );
        $appConfig->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
                $this->app[$key] = $value;
                if ($sensitive) {
                    $this->sensitive[$key] = true;
                }

                return true;
            }
        );
        $appConfig->method('deleteKey')->willReturnCallback(
            function (string $app, string $key): void {
                unset($this->app[$key]);
            }
        );

        $userConfig = $this->createStub(IUserConfig::class);
        $userConfig->method('getValueString')->willReturnCallback(
            fn (string $uid, string $app, string $key, string $default = ''): string => $this->user[$uid . '|' . $key] ?? $default
        );
        $userConfig->method('setValueString')->willReturnCallback(
            function (string $uid, string $app, string $key, string $value, bool $lazy = false, int $flags = 0): bool {
                $this->user[$uid . '|' . $key] = $value;
                if (($flags & IUserConfig::FLAG_SENSITIVE) !== 0) {
                    $this->sensitive[$uid . '|' . $key] = true;
                }

                return true;
            }
        );
        $userConfig->method('deleteUserConfig')->willReturnCallback(
            function (string $uid, string $app, string $key): void {
                unset($this->user[$uid . '|' . $key]);
            }
        );

        $http = $this->createStub(IClient::class);
        $http->method('request')->willReturnCallback(
            function (string $method, string $url, array $options = []): IResponse {
                $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
                [$status, $body] = ($this->respond)($method, $url, $options);
                $response = $this->createStub(IResponse::class);
                $response->method('getStatusCode')->willReturn($status);
                $response->method('getBody')->willReturn((string) json_encode($body));

                return $response;
            }
        );
        $clientService = $this->createStub(IClientService::class);
        $clientService->method('newClient')->willReturn($http);

        $synaplanConfig = $this->createStub(SynaplanConfig::class);
        $synaplanConfig->method('getBaseUrl')->willReturn('https://synaplan.example');

        $locks = $this->createStub(ILockingProvider::class);
        $locks->method('acquireLock')->willReturnCallback(
            fn (string $path) => ($this->onLock)($path)
        );

        return new OAuthClient($clientService, $appConfig, $config, $userConfig, $locks, $synaplanConfig, new NullLogger());
    }

    private function registered(): void
    {
        $this->app['link_instance_id'] = 'client-1';
        $this->app['oauth_private_key'] = self::$privateKey;
        $this->app['oauth_kid'] = 'kid-1';
        $this->app['notify_issuer'] = 'https://issuer.example/';
    }

    private function connected(string $refresh = 'rt-old', string $access = 'at-old', int $expires = 0): void
    {
        $this->user['ada|synaplan_oauth_refresh'] = $refresh;
        $this->user['ada|synaplan_oauth_access'] = $access;
        $this->user['ada|synaplan_oauth_expires'] = (string) $expires;
        $this->user['ada|synaplan_oauth_client'] = 'client-1';
    }

    /**
     * @return array<string, mixed>
     */
    private function form(int $index): array
    {
        parse_str((string) $this->requests[$index]['options']['body'], $fields);

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(int $index): array
    {
        return json_decode((string) $this->requests[$index]['options']['body'], true);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     */
    private static function decodeJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);

        return [
            json_decode(self::unb64($parts[0]), true),
            json_decode(self::unb64($parts[1]), true),
            $parts[0] . '.' . $parts[1],
            self::unb64($parts[2]),
        ];
    }

    private static function unb64(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param array<string, string> $jwk
     */
    private static function pemFromJwk(array $jwk): string
    {
        $length = static function (int $n): string {
            if ($n < 0x80) {
                return chr($n);
            }
            $bytes = ltrim(pack('N', $n), "\0");

            return chr(0x80 | strlen($bytes)) . $bytes;
        };
        $integer = static function (string $bytes) use ($length): string {
            if (ord($bytes[0]) > 0x7F) {
                $bytes = "\0" . $bytes;
            }

            return "\x02" . $length(strlen($bytes)) . $bytes;
        };
        $rsa = $integer(self::unb64($jwk['n'])) . $integer(self::unb64($jwk['e']));
        $rsa = "\x30" . $length(strlen($rsa)) . $rsa;
        $bits = "\x03" . $length(strlen($rsa) + 1) . "\0" . $rsa;
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $spki = "\x30" . $length(strlen($algorithm . $bits)) . $algorithm . $bits;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * @return array{redirect_uri: string, client_uri: string, delivery_url: string, capabilities: list<string>, server_id: string, version: string}
     */
    private static function metadata(): array
    {
        return [
            'redirect_uri' => 'https://files.example.org/apps/synaplan_integration/link/callback',
            'client_uri' => 'https://files.example.org/',
            'delivery_url' => 'https://files.example.org/inbound',
            'capabilities' => ['deliveries', 'pickups'],
            'server_id' => 'oc0abc',
            'version' => '2.0.0',
        ];
    }

    public function testRegisterSendsAPublicKeyAndKeepsTheClientId(): void
    {
        $this->respond = static fn (): array => [201, ['client_id' => 'client-1', 'status' => 'active']];
        $client = $this->client();

        $answer = $client->register(self::metadata(), 'iat-123');

        $this->assertSame('active', $answer['status']);
        $this->assertCount(1, $this->requests);
        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame('https://synaplan.example/oauth/register', $this->requests[0]['url']);
        $this->assertSame('Bearer iat-123', $this->requests[0]['options']['headers']['Authorization']);

        $body = $this->json(0);
        $this->assertSame('nextcloud', $body['software_id']);
        $this->assertSame('2.0.0', $body['software_version']);
        $this->assertSame('private_key_jwt', $body['token_endpoint_auth_method']);
        $this->assertSame(['authorization_code', 'refresh_token'], $body['grant_types']);
        $this->assertSame([self::metadata()['redirect_uri']], $body['redirect_uris']);
        $this->assertSame('https://files.example.org/inbound', $body['delivery_url']);
        $this->assertSame(['deliveries', 'pickups'], $body['app_capabilities']);
        $this->assertSame('oc0abc', $body['server_id']);

        $this->assertCount(1, $body['jwks']['keys']);
        $jwk = $body['jwks']['keys'][0];
        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame($this->app['oauth_kid'], $jwk['kid']);
        $this->assertNotSame('', $jwk['n']);
        $this->assertNotSame('', $jwk['e']);
        foreach (['d', 'p', 'q', 'dp', 'dq', 'qi'] as $private) {
            $this->assertArrayNotHasKey($private, $jwk);
        }

        $this->assertSame('client-1', $this->app['link_instance_id']);
        $this->assertSame('client-1', $client->clientId());
        $this->assertTrue($this->sensitive['oauth_private_key'] ?? false);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $this->requests[0]['options']['body']);
        $this->assertTrue($client->isRegistered());
    }

    public function testRegisterWithoutATokenSendsNoAuthorization(): void
    {
        $this->respond = static fn (): array => [201, ['client_id' => 'client-1', 'status' => 'pending']];

        $this->client()->register(self::metadata(), '');

        $this->assertArrayNotHasKey('Authorization', $this->requests[0]['options']['headers']);
    }

    public function testRegisterWithoutAClientIdIsARefusal(): void
    {
        $this->respond = static fn (): array => [201, ['status' => 'active']];

        try {
            $this->client()->register(self::metadata(), 'iat-123');
            $this->fail('A registration without a client id must not pass.');
        } catch (PlatformLinkException $e) {
            $this->assertSame(PlatformLinkException::CODE_EXCHANGE, $e->getErrorCode());
        }
        $this->assertArrayNotHasKey('link_instance_id', $this->app);
        $this->assertArrayNotHasKey('oauth_private_key', $this->app);
    }

    public function testARefusedRegistrationKeepsTheWorkingOne(): void
    {
        $this->registered();
        $this->respond = static fn (): array => [400, ['error' => 'invalid_client_metadata']];

        try {
            $this->client()->register(self::metadata(), '');
            $this->fail('A refused registration must throw.');
        } catch (PlatformLinkException) {
        }

        $this->assertSame('client-1', $this->app['link_instance_id']);
        $this->assertSame(self::$privateKey, $this->app['oauth_private_key']);
        $this->assertSame('kid-1', $this->app['oauth_kid']);
    }

    public function testTheClientAssertionIsSignedWithTheRegisteredKey(): void
    {
        $this->respond = static fn (): array => [201, ['client_id' => 'client-1']];
        $this->app['notify_issuer'] = 'https://issuer.example/';
        $client = $this->client();
        $client->register(self::metadata(), '');
        $jwk = $this->json(0)['jwks']['keys'][0];

        [$header, $payload, $signed, $signature] = self::decodeJwt($client->assertion());

        $this->assertSame(1, openssl_verify($signed, $signature, self::pemFromJwk($jwk), OPENSSL_ALGO_SHA256));
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame($jwk['kid'], $header['kid']);
        $this->assertSame('client-1', $payload['iss']);
        $this->assertSame('client-1', $payload['sub']);
        $this->assertSame('https://issuer.example', $payload['aud']);
        $this->assertLessThanOrEqual(time() - 30, $payload['iat']);
        $this->assertSame(90, $payload['exp'] - $payload['iat']);
        $this->assertNotSame('', $payload['jti']);
    }

    public function testTheAudienceIsReadFromSynaplanWhenNotKnownYet(): void
    {
        $this->registered();
        unset($this->app['notify_issuer']);
        $this->respond = static fn (string $method, string $url): array => str_ends_with($url, '/.well-known/synaplan-configuration')
            ? [200, ['issuer' => 'https://synaplan.example/']]
            : [500, []];

        [, $payload] = self::decodeJwt($this->client()->assertion());

        $this->assertSame('https://synaplan.example', $payload['aud']);
        $this->assertSame('GET', $this->requests[0]['method']);
    }

    public function testAnAssertionNeedsTheKey(): void
    {
        $this->registered();
        unset($this->app['oauth_private_key']);

        try {
            $this->client()->assertion();
            $this->fail('An assertion without a key must not be made.');
        } catch (PlatformLinkException $e) {
            $this->assertSame(PlatformLinkException::CODE_NOT_REGISTERED, $e->getErrorCode());
        }
    }

    public function testTheAuthorizationRequestIsPushedAndTheBrowserCarriesOnlyItsReference(): void
    {
        $this->registered();
        $this->respond = static fn (): array => [201, ['request_uri' => 'urn:ietf:params:oauth:request_uri:abc', 'expires_in' => 60]];

        $authorize = $this->client()->authorizeUrl('ada', 'state-1', 'https://files.example.org/callback', false);

        $this->assertSame('https://synaplan.example/oauth/par', $this->requests[0]['url']);
        $pushed = $this->form(0);
        $this->assertSame('code', $pushed['response_type']);
        $this->assertSame('https://files.example.org/callback', $pushed['redirect_uri']);
        $this->assertSame(OAuthClient::SCOPE, $pushed['scope']);
        $this->assertSame('state-1', $pushed['state']);
        $this->assertSame('ada', $pushed['external_id']);
        $this->assertSame('S256', $pushed['code_challenge_method']);
        $this->assertSame(self::b64(hash('sha256', $authorize['verifier'], true)), $pushed['code_challenge']);
        $this->assertArrayHasKey('client_assertion', $pushed);
        $this->assertGreaterThanOrEqual(43, strlen($authorize['verifier']));

        $this->assertStringStartsWith('https://issuer.example/oauth/authorize?', $authorize['url']);
        parse_str((string) parse_url($authorize['url'], PHP_URL_QUERY), $query);
        $this->assertSame(['client_id' => 'client-1', 'request_uri' => 'urn:ietf:params:oauth:request_uri:abc'], $query);
    }

    public function testTheAuthorizationRequestAsksForMemoriesWhenTheyAreOn(): void
    {
        $this->registered();
        $this->respond = static fn (): array => [201, ['request_uri' => 'urn:ietf:params:oauth:request_uri:abc']];
        $client = $this->client();

        $first = $client->authorizeUrl('ada', 's', 'https://cb', true);
        $second = $client->authorizeUrl('ada', 's', 'https://cb', true);

        $this->assertSame(OAuthClient::SCOPE . ' memories', $this->form(0)['scope']);
        $this->assertNotSame($first['verifier'], $second['verifier']);
    }

    public function testARefusedAuthorizationRequestIsReported(): void
    {
        $this->registered();
        $this->respond = static fn (): array => [400, ['error' => 'invalid_request']];

        $this->expectException(PlatformLinkException::class);

        $this->client()->authorizeUrl('ada', 's', 'https://cb', false);
    }

    public function testConnectExchangesTheCodeAndKeepsTheGrant(): void
    {
        $this->registered();
        $this->respond = static fn (): array => [200, ['access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 300]];
        $client = $this->client();

        $client->connect('ada', 'code-1', 'verifier-1', 'https://cb');

        $this->assertSame('https://synaplan.example/oauth/token', $this->requests[0]['url']);
        $this->assertSame('application/x-www-form-urlencoded', $this->requests[0]['options']['headers']['Content-Type']);
        $form = $this->form(0);
        $this->assertSame('authorization_code', $form['grant_type']);
        $this->assertSame('code-1', $form['code']);
        $this->assertSame('verifier-1', $form['code_verifier']);
        $this->assertSame('https://cb', $form['redirect_uri']);
        $this->assertSame('client-1', $form['client_id']);
        $this->assertSame('urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $form['client_assertion_type']);
        $this->assertSame('client-1', self::decodeJwt((string) $form['client_assertion'])[1]['iss']);

        $this->assertSame('rt-1', $this->user['ada|synaplan_oauth_refresh']);
        $this->assertSame('at-1', $this->user['ada|synaplan_oauth_access']);
        $this->assertTrue($this->sensitive['ada|synaplan_oauth_refresh'] ?? false);
        $this->assertTrue($this->sensitive['ada|synaplan_oauth_access'] ?? false);
        $this->assertEqualsWithDelta(time() + 300, (int) $this->user['ada|synaplan_oauth_expires'], 5);
        $this->assertTrue($client->isConnected('ada'));
    }

    public function testConnectWithoutTokensIsARefusal(): void
    {
        $this->registered();
        $this->respond = static fn (): array => [200, ['access_token' => 'at-1']];

        $this->expectException(PlatformLinkException::class);
        $this->client()->connect('ada', 'code-1', 'verifier-1', 'https://cb');
    }

    public function testAccessTokenIsTheCachedOneWhileValid(): void
    {
        $this->registered();
        $this->connected('rt-old', 'at-cached', time() + 600);

        $this->assertSame('at-cached', $this->client()->accessToken('ada'));
        $this->assertSame([], $this->requests);
    }

    public function testAccessTokenRefreshesAndKeepsTheRotatedRefreshToken(): void
    {
        $this->registered();
        $this->connected('rt-old', 'at-old', time() - 10);
        $this->respond = static fn (): array => [200, ['access_token' => 'at-new', 'refresh_token' => 'rt-new', 'expires_in' => 300]];

        $this->assertSame('at-new', $this->client()->accessToken('ada'));

        $form = $this->form(0);
        $this->assertSame('refresh_token', $form['grant_type']);
        $this->assertSame('rt-old', $form['refresh_token']);
        $this->assertArrayHasKey('client_assertion', $form);
        $this->assertSame('rt-new', $this->user['ada|synaplan_oauth_refresh']);
        $this->assertSame('at-new', $this->user['ada|synaplan_oauth_access']);
    }

    public function testAnAccessTokenAboutToRunOutIsRefreshed(): void
    {
        $this->registered();
        $this->connected('rt-old', 'at-old', time() + 10);
        $this->respond = static fn (): array => [200, ['access_token' => 'at-new', 'refresh_token' => 'rt-new', 'expires_in' => 300]];

        $this->assertSame('at-new', $this->client()->accessToken('ada'));
    }

    public function testAccessTokenForgetsTheGrantOnInvalidGrant(): void
    {
        $this->registered();
        $this->connected('rt-old', 'at-old', time() - 10);
        $this->respond = static fn (): array => [400, ['error' => 'invalid_grant']];
        $client = $this->client();

        $this->assertSame('', $client->accessToken('ada'));
        $this->assertSame([], $this->user);
        $this->assertFalse($client->isConnected('ada'));
    }

    public function testAccessTokenKeepsTheGrantWhenSynaplanFailsOtherwise(): void
    {
        $this->registered();
        $this->connected('rt-old', 'at-old', time() - 10);
        $this->respond = static fn (): array => [503, ['error' => 'temporarily_unavailable']];
        $client = $this->client();

        $this->assertSame('', $client->accessToken('ada'));
        $this->assertSame('rt-old', $this->user['ada|synaplan_oauth_refresh']);
        $this->assertTrue($client->isConnected('ada'));
    }

    public function testARefreshWhoseAnswerGotLostIsAskedAgainAtOnce(): void
    {
        $this->registered();
        $this->connected('rt-old', 'at-old', time() - 10);
        $calls = 0;
        $this->respond = static function () use (&$calls): array {
            if (++$calls === 1) {
                throw new \RuntimeException('Operation timed out');
            }

            return [200, ['access_token' => 'at-new', 'refresh_token' => 'rt-new', 'expires_in' => 300]];
        };

        $this->assertSame('at-new', $this->client()->accessToken('ada'));
        $this->assertCount(2, $this->requests);
        $this->assertSame('rt-old', $this->form(1)['refresh_token']);
        $this->assertNotSame($this->form(0)['client_assertion'], $this->form(1)['client_assertion'], 'an assertion is good for one request');
        $this->assertSame('rt-new', $this->user['ada|synaplan_oauth_refresh']);
    }

    public function testAnUnreachableSynaplanIsAskedOnceMoreAndTheGrantKept(): void
    {
        $this->registered();
        $this->connected('rt-old', 'at-old', time() - 10);
        $this->respond = static function (): array {
            throw new \RuntimeException('Connection refused');
        };
        $client = $this->client();

        $this->assertSame('', $client->accessToken('ada'));
        $this->assertCount(2, $this->requests);
        $this->assertSame('rt-old', $this->user['ada|synaplan_oauth_refresh']);
    }

    public function testARefusedClientKeepsThePersonsGrant(): void
    {
        $this->registered();
        $this->connected('rt-old', 'at-old', time() - 10);
        $this->respond = static fn (): array => [401, ['error' => 'invalid_client']];
        $client = $this->client();

        $this->assertSame('', $client->accessToken('ada'));
        $this->assertTrue($client->isConnected('ada'));
    }

    public function testARefreshUnderWayElsewhereIsWaitedForAndItsTokenTaken(): void
    {
        $this->registered();
        $this->connected('rt-old', 'at-old', time() - 10);
        $first = true;
        $this->onLock = function (string $path) use (&$first): void {
            if ($first) {
                $first = false;
                // The other request finishes its refresh while this one waits.
                $this->connected('rt-new', 'at-new', time() + 300);

                throw new LockedException($path);
            }
        };

        $this->assertSame('at-new', $this->client()->accessToken('ada'));
        $this->assertSame([], $this->requests);
    }

    public function testAGrantOfAnEarlierRegistrationIsNone(): void
    {
        $this->registered();
        $this->connected('rt-old', 'at-old', time() + 600);
        $this->user['ada|synaplan_oauth_client'] = 'client-0';
        $client = $this->client();

        $this->assertFalse($client->isConnected('ada'));
        $this->assertSame('', $client->accessToken('ada'));
        $this->assertSame([], $this->requests);
    }

    public function testAccessTokenWithoutAGrantAsksNothing(): void
    {
        $this->registered();

        $this->assertSame('', $this->client()->accessToken('ada'));
        $this->assertSame([], $this->requests);
    }

    public function testAValueThatNoLongerDecryptsIsNoGrant(): void
    {
        $this->registered();
        $this->connected('$UserConfigEncryption$b4d7|cipher', '$UserConfigEncryption$b4d7|cipher', time() + 600);
        $client = $this->client();

        $this->assertFalse($client->isConnected('ada'));
        $this->assertSame('', $client->accessToken('ada'));
        $this->assertSame([], $this->requests);
    }

    public function testRevokePostsTheRefreshTokenAndForgetsTheGrant(): void
    {
        $this->registered();
        $this->connected('rt-old', 'at-old', time() + 600);
        $client = $this->client();

        $client->revoke('ada');

        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame('https://synaplan.example/oauth/revoke', $this->requests[0]['url']);
        $form = $this->form(0);
        $this->assertSame('rt-old', $form['token']);
        $this->assertSame('urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $form['client_assertion_type']);
        $this->assertArrayHasKey('client_assertion', $form);
        $this->assertSame([], $this->user);
        $this->assertFalse($client->isConnected('ada'));
    }

    public function testRevokeKeepsTheGrantWhenSynaplanCannotBeTold(): void
    {
        $this->registered();
        $this->connected();
        $this->respond = static fn (): array => [500, []];

        try {
            $this->client()->revoke('ada');
            $this->fail('A failed revocation must be reported.');
        } catch (PlatformLinkException) {
        }

        $this->assertCount(1, $this->requests);
        $this->assertSame('rt-old', $this->user['ada|synaplan_oauth_refresh']);
    }

    public function testRevokeOfAGrantSynaplanAlreadyEndedForgetsIt(): void
    {
        $this->registered();
        $this->connected();
        $this->respond = static fn (): array => [400, ['error' => 'invalid_grant']];

        $this->client()->revoke('ada');

        $this->assertSame([], $this->user);
    }

    public function testRevokeAtASynaplanThatRefusesThisClientForgetsTheGrant(): void
    {
        $this->registered();
        $this->connected();
        $this->respond = static fn (): array => [401, ['error' => 'invalid_client']];
        $client = $this->client();

        $client->revoke('ada');

        $this->assertCount(1, $this->requests);
        $this->assertSame([], $this->user);
        $this->assertFalse($client->isConnected('ada'));
    }

    public function testARefusedClientIsToldApartFromAnotherRefusal(): void
    {
        $this->registered();
        $client = $this->client();

        // The assertion is the bearer on the registration's own address.
        $this->respond = static fn (): array => [401, ['error' => 'invalid_token']];
        $this->assertSame(PlatformLinkException::CODE_CLIENT_REFUSED, $this->codeOf(static fn () => $client->configuration()));

        $this->respond = static fn (): array => [401, ['error' => 'invalid_client']];
        $this->assertSame(PlatformLinkException::CODE_CLIENT_REFUSED, $this->codeOf(static fn () => $client->authorizeUrl('ada', 's', 'https://cb', false)));

        $this->respond = static fn (): array => [429, ['error' => 'too_many_requests']];
        $this->assertSame(PlatformLinkException::CODE_EXCHANGE, $this->codeOf(static fn () => $client->configuration()));
    }

    public function testAMissingRegistrationCodeIsNotARefusedClient(): void
    {
        $this->respond = static fn (): array => [401, ['error' => 'invalid_token']];
        $client = $this->client();

        $this->assertSame(
            PlatformLinkException::CODE_EXCHANGE,
            $this->codeOf(static fn () => $client->register(self::metadata(), '')),
        );
    }

    private function codeOf(callable $call): string
    {
        try {
            $call();
        } catch (PlatformLinkException $e) {
            return $e->getErrorCode();
        }

        return '';
    }

    public function testUnregisterEndsTheRegistrationAndDropsTheKey(): void
    {
        $this->registered();
        $client = $this->client();

        $client->unregister();

        $this->assertSame('DELETE', $this->requests[0]['method']);
        $this->assertSame('https://synaplan.example/oauth/register/client-1', $this->requests[0]['url']);
        $bearer = (string) $this->requests[0]['options']['headers']['Authorization'];
        $this->assertStringStartsWith('Bearer ', $bearer);
        $this->assertSame('client-1', self::decodeJwt(substr($bearer, 7))[1]['sub']);
        $this->assertSame('https://issuer.example/oauth/register/client-1', self::decodeJwt(substr($bearer, 7))[1]['aud']);
        $this->assertArrayNotHasKey('link_instance_id', $this->app);
        $this->assertArrayNotHasKey('oauth_private_key', $this->app);
        $this->assertArrayNotHasKey('oauth_kid', $this->app);
        $this->assertFalse($client->isRegistered());
    }

    public function testConfigurationAndItsUpdateAuthenticateWithAnAssertion(): void
    {
        $this->registered();
        $this->respond = static fn (): array => [200, ['status' => 'active']];
        $client = $this->client();

        $this->assertSame(['status' => 'active'], $client->configuration());
        $client->updateConfiguration(['delivery_url' => 'https://files.example.org/inbound']);

        $this->assertSame('GET', $this->requests[0]['method']);
        $this->assertSame('PUT', $this->requests[1]['method']);
        $this->assertSame('https://synaplan.example/oauth/register/client-1', $this->requests[1]['url']);
        $this->assertSame(['delivery_url' => 'https://files.example.org/inbound'], $this->json(1));
        foreach ($this->requests as $request) {
            $bearer = (string) $request['options']['headers']['Authorization'];
            $this->assertStringStartsWith('Bearer ey', $bearer);
            $this->assertSame('https://issuer.example/oauth/register/client-1', self::decodeJwt(substr($bearer, 7))[1]['aud']);
        }
    }

    public function testALifecycleChangeIsReportedWithAnAssertion(): void
    {
        $this->registered();
        $this->respond = static fn (): array => [200, ['success' => true]];

        $this->client()->reportLifecycle('ada', 'disabled');

        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame('https://synaplan.example/oauth/register/client-1/lifecycle', $this->requests[0]['url']);
        $this->assertSame(['external_id' => 'ada', 'event' => 'disabled'], $this->json(0));
        $bearer = (string) $this->requests[0]['options']['headers']['Authorization'];
        $this->assertStringStartsWith('Bearer ey', $bearer);
        $this->assertSame('https://issuer.example/oauth/register/client-1/lifecycle', self::decodeJwt(substr($bearer, 7))[1]['aud']);
    }
}
