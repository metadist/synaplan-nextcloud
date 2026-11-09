<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Exception\PlatformLinkException;
use OCA\SynaplanIntegration\Service\OAuthClient;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PlatformLinkServiceTest extends TestCase
{
    private IClientService&MockObject $clientService;
    private IClient&MockObject $httpClient;
    private IConfig&MockObject $config;
    private IURLGenerator&MockObject $urlGenerator;
    private OAuthClient&MockObject $oauth;
    private IAppManager&MockObject $appManager;
    private SynaplanConfig&MockObject $synaplanConfig;
    private UserAccountService&MockObject $userAccounts;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->clientService = $this->createMock(IClientService::class);
        $this->httpClient = $this->createMock(IClient::class);
        $this->config = $this->createMock(IConfig::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->oauth = $this->createMock(OAuthClient::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->synaplanConfig = $this->createMock(SynaplanConfig::class);
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->clientService->method('newClient')->willReturn($this->httpClient);
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturn(
            'https://files.example.org/apps/synaplan_integration/link/callback'
        );
        $this->synaplanConfig->method('getBaseUrl')->willReturn('https://synaplan.example');
    }

    private function service(): PlatformLinkService
    {
        return new PlatformLinkService(
            $this->clientService,
            $this->config,
            $this->urlGenerator,
            $this->oauth,
            $this->appManager,
            $this->synaplanConfig,
            $this->userAccounts,
            $this->logger,
        );
    }

    public function testRegisterSendsThisServerAndWhatTheAppDoes(): void
    {
        $this->synaplanConfig->method('getInstanceId')->willReturn('oc0abc');
        $this->config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $key === 'delivery_url' ? 'https://files.example.org/inbound' : $default
        );
        $this->urlGenerator->method('getAbsoluteURL')->with('/')->willReturn('https://files.example.org/');
        $this->appManager->method('getAppVersion')->willReturn('2.0.0');
        $this->oauth->method('clientId')->willReturn('client-1');
        $this->oauth->expects($this->once())->method('register')
            ->with([
                'client_uri' => 'https://files.example.org/',
                'redirect_uri' => 'https://files.example.org/apps/synaplan_integration/link/callback',
                'delivery_url' => 'https://files.example.org/inbound',
                'capabilities' => ['deliveries', 'pickups', 'read_scope', 'source_events'],
                'server_id' => 'oc0abc',
                'version' => '2.0.0',
            ], 'reg-token')
            ->willReturn(['client_id' => 'client-1', 'status' => 'active']);

        $this->assertSame(
            ['instance_id' => 'client-1', 'status' => 'active'],
            $this->service()->register('reg-token'),
        );
    }

    public function testRegistrationStatusReadsTheClientConfiguration(): void
    {
        $this->oauth->method('isRegistered')->willReturn(true);
        $this->oauth->method('configuration')->willReturn([
            'status' => 'active',
            'client_uri' => 'https://files.example.org/',
            'delivery_url' => 'https://files.example.org/inbound',
            'reachable' => true,
        ]);

        $status = $this->service()->registrationStatus();

        $this->assertSame([
            'status' => 'active',
            'host' => 'https://files.example.org',
            'delivery_url' => 'https://files.example.org/inbound',
            'reachable' => true,
        ], $status);
    }

    public function testRegistrationStatusIsNullWhenNotRegistered(): void
    {
        $this->oauth->method('isRegistered')->willReturn(false);
        $this->oauth->expects($this->never())->method('configuration');

        $this->assertNull($this->service()->registrationStatus());
    }

    public function testForgetRegistrationLeavesSynaplan(): void
    {
        $this->oauth->expects($this->once())->method('unregister');

        $this->service()->forgetRegistration();
    }

    public function testWhoAmIAsksWithThePersonsAccessToken(): void
    {
        $this->userAccounts->method('getStoredApiKey')->with('jdoe')->willReturn('at_jdoe');
        $resp = $this->createMock(IResponse::class);
        $resp->method('getStatusCode')->willReturn(200);
        $resp->method('getBody')->willReturn('{"user":{"id":9,"email":"j@doe.test"}}');
        $this->httpClient->expects($this->once())->method('get')
            ->with(
                'https://synaplan.example/api/v1/auth/me',
                $this->callback(fn (array $o): bool => ($o['headers']['Authorization'] ?? '') === 'Bearer at_jdoe'
                    && !isset($o['headers']['X-API-Key']))
            )
            ->willReturn($resp);

        $this->assertSame(['id' => 9, 'email' => 'j@doe.test'], $this->service()->whoAmI('jdoe'));
    }

    public function testWhoAmINeedsACredential(): void
    {
        $this->userAccounts->method('getStoredApiKey')->willReturn('');
        $this->httpClient->expects($this->never())->method('get');

        $this->expectException(\RuntimeException::class);
        $this->service()->whoAmI('jdoe');
    }

    public function testDisconnectRevokesTheGrantOfAConnectedPerson(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jdoe');
        $this->oauth->method('isConnected')->with('jdoe')->willReturn(true);
        $this->oauth->expects($this->once())->method('revoke')->with('jdoe');
        $this->httpClient->expects($this->never())->method('delete');
        $this->userAccounts->expects($this->once())->method('deactivateUser')->with('jdoe');

        $this->service()->disconnect($user);
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
        $this->synaplanConfig->method('getInstanceId')->willReturn('oc0abc');
        $this->appManager->method('getAppVersion')->willReturn('2.0.0');
        $this->oauth->method('isRegistered')->willReturn(true);
        $this->oauth->expects($this->once())->method('updateConfiguration')->with([
            'delivery_url' => 'http://nextcloud/index.php/apps/synaplan_integration/api/v1/inbound',
            'server_id' => 'oc0abc',
            'app_capabilities' => ['deliveries', 'pickups', 'read_scope', 'source_events'],
            'software_version' => '2.0.0',
        ])->willReturn([]);
        $this->httpClient->expects($this->never())->method($this->anything());

        $this->service()->announceDelivery('http://nextcloud/index.php/apps/synaplan_integration/api/v1/inbound');
    }

    public function testAnnounceWaitsForTheRegistration(): void
    {
        $this->oauth->method('isRegistered')->willReturn(false);
        $this->oauth->expects($this->never())->method('updateConfiguration');

        $this->service()->announceDelivery('http://nextcloud/inbound');
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
