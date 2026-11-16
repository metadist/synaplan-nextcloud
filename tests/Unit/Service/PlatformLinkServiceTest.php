<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Exception\PlatformLinkException;
use OCA\SynaplanIntegration\Service\CloudClientKey;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PlatformLinkServiceTest extends TestCase
{
    private IClientService&MockObject $clientService;
    private IClient&MockObject $httpClient;
    private IConfig&MockObject $config;
    private IURLGenerator&MockObject $urlGenerator;
    private ICrypto&MockObject $crypto;
    private SynaplanConfig&MockObject $synaplanConfig;
    private UserAccountService&MockObject $userAccounts;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->clientService = $this->createMock(IClientService::class);
        $this->httpClient = $this->createMock(IClient::class);
        $this->config = $this->createMock(IConfig::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->crypto = $this->createMock(ICrypto::class);
        $this->synaplanConfig = $this->createMock(SynaplanConfig::class);
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->clientService->method('newClient')->willReturn($this->httpClient);
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturn(
            'https://files.example.org/apps/synaplan_integration/link/callback'
        );
        $this->synaplanConfig->method('getBaseUrl')->willReturn('https://synaplan.example');
        $this->crypto->method('encrypt')->willReturnCallback(fn (string $s) => 'enc:' . $s);
        $this->crypto->method('decrypt')->willReturnCallback(
            fn (string $s) => str_starts_with($s, 'enc:') ? substr($s, 4) : $s
        );
    }

    private function service(): PlatformLinkService
    {
        return new PlatformLinkService(
            $this->clientService,
            $this->config,
            $this->urlGenerator,
            $this->synaplanConfig,
            $this->userAccounts,
            $this->logger,
            new CloudClientKey($this->config, $this->crypto, $this->synaplanConfig),
        );
    }

    public function testExchangePostsTheCodeAndReadsThePerson(): void
    {
        $this->synaplanConfig->method('getLinkInstanceId')->willReturn('pi_abc');
        $this->synaplanConfig->method('getLinkInstanceSecretCipher')->willReturn('enc:s3cret');
        $this->synaplanConfig->method('getPublicBaseUrl')->willReturn('https://synaplan.example');

        $token = $this->createMock(IResponse::class);
        $token->method('getStatusCode')->willReturn(200);
        $token->method('getBody')->willReturn(json_encode([
            'access_token' => 'header.payload.signature',
            'refresh_token' => 'refresh-token',
            'expires_in' => 900,
            'token_type' => 'Bearer',
        ]));
        $me = $this->createMock(IResponse::class);
        $me->method('getStatusCode')->willReturn(200);
        $me->method('getBody')->willReturn(json_encode([
            'success' => true,
            'user' => ['id' => 2, 'email' => 'a@b.test'],
        ]));

        $captured = [];
        $this->httpClient->expects($this->once())
            ->method('post')
            ->willReturnCallback(function (string $url, array $options) use (&$captured, $token): IResponse {
                parse_str((string) $options['body'], $parsed);
                $captured = [$url, $parsed];

                return $token;
            });
        $this->httpClient->expects($this->once())->method('get')->willReturn($me);

        $result = $this->service()->exchange('the-code', 'verifier-1');

        $this->assertSame('https://synaplan.example/oauth/token', $captured[0]);
        $this->assertSame('authorization_code', $captured[1]['grant_type']);
        $this->assertSame('the-code', $captured[1]['code']);
        $this->assertSame('verifier-1', $captured[1]['code_verifier']);
        $this->assertSame('header.payload.signature', $result['access_token']);
        $this->assertSame('a@b.test', $result['user']['email']);
    }

    public function testSecretIsNeverLogged(): void
    {
        $this->synaplanConfig->method('getLinkInstanceId')->willReturn('pi_abc');
        $this->synaplanConfig->method('getLinkInstanceSecretCipher')->willReturn('enc:super-secret-value');
        $this->synaplanConfig->method('getAdminApiKey')->willReturn('');

        $resp = $this->createMock(IResponse::class);
        $resp->method('getStatusCode')->willReturn(201);
        $resp->method('getBody')->willReturn(json_encode([
            'client_id' => 'pi_new',
            'status' => 'pending',
        ]));
        $this->httpClient->method('post')->willReturn($resp);
        $this->config->method('setAppValue');

        $this->logger->expects($this->atLeastOnce())->method('info')
            ->with(
                $this->anything(),
                $this->callback(function (array $context): bool {
                    $blob = json_encode($context) ?: '';

                    return !str_contains($blob, 'super-secret-value')
                        && !str_contains($blob, 'BEGIN PRIVATE KEY');
                })
            );

        $this->service()->registerInstance();
    }

    public function testDisconnectRevokesKeyThenClearsUser(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jdoe');
        $this->userAccounts->method('getStoredApiKeyId')->with('jdoe')->willReturn('44');
        $this->userAccounts->method('getStoredApiKey')->with('jdoe')->willReturn('sk_user');

        $resp = $this->createMock(IResponse::class);
        $resp->method('getBody')->willReturn('{"success":true}');
        $this->httpClient->expects($this->once())
            ->method('delete')
            ->with(
                'https://synaplan.example/api/v1/apikeys/44',
                $this->callback(fn (array $o): bool => ($o['headers']['X-API-Key'] ?? '') === 'sk_user')
            )
            ->willReturn($resp);
        $this->userAccounts->expects($this->once())->method('deactivateUser')->with('jdoe');

        $this->service()->disconnect($user);
    }

    public function testDisconnectKeepsUserWhenRevokeFails(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jdoe');
        $this->userAccounts->method('getStoredApiKeyId')->with('jdoe')->willReturn('44');
        $this->userAccounts->method('getStoredApiKey')->with('jdoe')->willReturn('sk_user');
        $this->httpClient->method('delete')->willThrowException(new \RuntimeException('HTTP 500'));
        $this->userAccounts->expects($this->never())->method('deactivateUser');

        $this->expectException(PlatformLinkException::class);
        $this->service()->disconnect($user);
    }

    public function testAnnounceNamesThisServerAndWhatTheAppDoes(): void
    {
        $this->synaplanConfig->method('getLinkInstanceId')->willReturn('pi_abc');
        $this->synaplanConfig->method('getLinkInstanceSecretCipher')->willReturn('enc:s3cret');
        $this->synaplanConfig->method('getInstanceId')->willReturn('oc0abc');
        $this->synaplanConfig->method('getPublicBaseUrl')->willReturn('https://synaplan.example');

        $resp = $this->createMock(IResponse::class);
        $resp->method('getStatusCode')->willReturn(200);
        $resp->method('getBody')->willReturn('{"success":true}');
        $body = null;
        $this->httpClient->expects($this->once())
            ->method('put')
            ->willReturnCallback(function (string $url, array $options) use (&$body, $resp): IResponse {
                $this->assertStringEndsWith('/oauth/register/pi_abc', $url);
                $body = json_decode($options['body'], true);

                return $resp;
            });

        $this->service()->announceDelivery('http://nextcloud/index.php/apps/synaplan_integration/api/v1/inbound');

        $this->assertSame('oc0abc', $body['server_id']);
        $this->assertSame(['deliveries', 'pickups', 'read_scope', 'source_events'], $body['app_capabilities']);
        $this->assertSame('http://nextcloud/index.php/apps/synaplan_integration/api/v1/inbound', $body['delivery_url']);
    }

    public function testLifecycleUsesAClientAssertion(): void
    {
        $this->synaplanConfig->method('getLinkInstanceId')->willReturn('pi_abc');
        $this->synaplanConfig->method('getLinkInstanceSecretCipher')->willReturn('enc:s3cret');
        $this->synaplanConfig->method('getPublicBaseUrl')->willReturn('https://synaplan.example');

        $resp = $this->createMock(IResponse::class);
        $resp->method('getStatusCode')->willReturn(200);
        $resp->method('getBody')->willReturn('{"success":true}');
        $seen = [];
        $this->httpClient->method('post')->willReturnCallback(
            function (string $url, array $options) use (&$seen, $resp): IResponse {
                $seen = [$url, json_decode($options['body'], true), $options['headers']];

                return $resp;
            }
        );

        $this->service()->reportLifecycle('ada', 'disabled');

        $this->assertStringEndsWith('/oauth/register/pi_abc/lifecycle', $seen[0]);
        $this->assertSame(['external_id' => 'ada', 'event' => 'disabled'], $seen[1]);
        $this->assertStringStartsWith('Bearer ', (string) ($seen[2]['Authorization'] ?? ''));
    }

    public function testACopyIsClaimedWithThePersonsOwnCredential(): void
    {
        $cases = [
            'sk_ada' => ['X-API-Key' => 'sk_ada'],
            'aaa.bbb.ccc' => ['Authorization' => 'Bearer aaa.bbb.ccc'],
        ];
        foreach ($cases as $stored => $expected) {
            $this->userAccounts = $this->createMock(UserAccountService::class);
            $this->userAccounts->method('credentialFor')->with('ada')->willReturn($stored);
            $resp = $this->createMock(IResponse::class);
            $resp->method('getStatusCode')->willReturn(200);
            $resp->method('getBody')->willReturn('{"success":true}');
            $this->httpClient = $this->createMock(IClient::class);
            $this->clientService = $this->createMock(IClientService::class);
            $this->clientService->method('newClient')->willReturn($this->httpClient);
            $this->httpClient->expects($this->once())
                ->method('post')
                ->with(
                    'https://synaplan.example/api/v1/platform-links/deliveries/9/claim',
                    $this->callback(function (array $options) use ($expected): bool {
                        foreach ($expected as $name => $value) {
                            if (($options['headers'][$name] ?? '') !== $value) {
                                return false;
                            }
                        }

                        return !isset($options['headers']['X-API-Key'], $options['headers']['Authorization'])
                            || count(array_intersect_key($options['headers'], ['X-API-Key' => true, 'Authorization' => true])) === 1;
                    })
                )
                ->willReturn($resp);

            $this->service()->claimDelivery('9', 'ada');
        }
    }

    public function testDisconnectUidClearsEvenWhenRevokeFails(): void
    {
        $this->userAccounts->method('getStoredApiKeyId')->with('jdoe')->willReturn('44');
        $this->userAccounts->method('getStoredApiKey')->with('jdoe')->willReturn('sk_user');
        $this->httpClient->method('delete')->willThrowException(new \RuntimeException('HTTP 500'));
        $this->userAccounts->expects($this->once())->method('deactivateUser')->with('jdoe');

        $this->service()->disconnectUid('jdoe');
    }
}
