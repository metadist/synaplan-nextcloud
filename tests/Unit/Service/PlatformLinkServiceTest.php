<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Exception\PlatformLinkException;
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
            $this->crypto,
            $this->synaplanConfig,
            $this->userAccounts,
            $this->logger,
        );
    }

    public function testExchangePostsInstanceCredentialsAndCode(): void
    {
        $this->synaplanConfig->method('getLinkInstanceId')->willReturn('pi_abc');
        $this->synaplanConfig->method('getLinkInstanceSecretCipher')->willReturn('enc:s3cret');

        $resp = $this->createMock(IResponse::class);
        $resp->method('getBody')->willReturn(json_encode([
            'success' => true,
            'api_key' => ['id' => 4, 'key' => 'sk_linked', 'name' => 'Nextcloud'],
            'user' => ['id' => 2, 'email' => 'a@b.test', 'display_name' => 'Ada'],
            'link_id' => 7,
        ]));

        $captured = [];
        $this->httpClient->expects($this->once())
            ->method('post')
            ->willReturnCallback(function (string $url, array $options) use (&$captured, $resp): IResponse {
                $captured = [$url, json_decode($options['body'], true)];

                return $resp;
            });

        $result = $this->service()->exchange('the-code');

        $this->assertSame('https://synaplan.example/api/v1/platform-links/exchange', $captured[0]);
        $this->assertSame('pi_abc', $captured[1]['instance_id']);
        $this->assertSame('s3cret', $captured[1]['instance_secret']);
        $this->assertSame('the-code', $captured[1]['code']);
        $this->assertSame('sk_linked', $result['api_key']['key']);
    }

    public function testSecretIsNeverLogged(): void
    {
        $this->synaplanConfig->method('getLinkInstanceId')->willReturn('pi_abc');
        $this->synaplanConfig->method('getLinkInstanceSecretCipher')->willReturn('enc:super-secret-value');
        $this->synaplanConfig->method('getAdminApiKey')->willReturn('');

        $resp = $this->createMock(IResponse::class);
        $resp->method('getBody')->willReturn(json_encode([
            'instance_id' => 'pi_new',
            'instance_secret' => 'shown-once',
            'status' => 'pending',
        ]));
        $this->httpClient->method('post')->willReturn($resp);
        $this->config->method('setAppValue');

        $this->logger->expects($this->atLeastOnce())->method('info')
            ->with(
                $this->anything(),
                $this->callback(function (array $context): bool {
                    $blob = json_encode($context) ?: '';

                    return !str_contains($blob, 'shown-once')
                        && !str_contains($blob, 'super-secret-value');
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

    public function testDisconnectUidClearsEvenWhenRevokeFails(): void
    {
        $this->userAccounts->method('getStoredApiKeyId')->with('jdoe')->willReturn('44');
        $this->userAccounts->method('getStoredApiKey')->with('jdoe')->willReturn('sk_user');
        $this->httpClient->method('delete')->willThrowException(new \RuntimeException('HTTP 500'));
        $this->userAccounts->expects($this->once())->method('deactivateUser')->with('jdoe');

        $this->service()->disconnectUid('jdoe');
    }
}
