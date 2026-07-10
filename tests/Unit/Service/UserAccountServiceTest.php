<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserAccountServiceTest extends TestCase
{
    private IClientService&MockObject $clientService;
    private IClient&MockObject $httpClient;
    private IConfig&MockObject $config;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;

    /** @var array<string, string> */
    private array $appConfig = [];
    /** @var array<string, string> */
    private array $userConfig = [];

    protected function setUp(): void
    {
        $this->clientService = $this->createMock(IClientService::class);
        $this->httpClient = $this->createMock(IClient::class);
        $this->config = $this->createMock(IConfig::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->clientService->method('newClient')->willReturn($this->httpClient);

        $this->appConfig = [
            'active_env' => 'local',
            'synaplan_url_local' => 'http://localhost:8000',
            'api_key_local' => 'sk_admin_key',
            'per_user_accounts' => '1',
        ];
        $this->userConfig = [];

        $this->config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => $this->appConfig[$key] ?? $default
        );
        $this->config->method('getSystemValueString')->willReturn('inst42');
        $this->config->method('getUserValue')->willReturnCallback(
            fn (string $uid, string $app, string $key, string $default = '') => $this->userConfig[$uid.'|'.$key] ?? $default
        );
        $this->config->method('setUserValue')->willReturnCallback(
            function (string $uid, string $app, string $key, string $value): void {
                $this->userConfig[$uid.'|'.$key] = $value;
            }
        );
        $this->config->method('deleteUserValue')->willReturnCallback(
            function (string $uid, string $app, string $key): void {
                unset($this->userConfig[$uid.'|'.$key]);
            }
        );
    }

    private function service(): UserAccountService
    {
        return new UserAccountService(
            $this->clientService,
            $this->config,
            $this->userSession,
            new SynaplanConfig($this->config),
            $this->logger,
        );
    }

    private function mockUser(string $uid, ?string $email, string $displayName): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getEMailAddress')->willReturn($email);
        $user->method('getDisplayName')->willReturn($displayName);

        return $user;
    }

    public function testReturnsNullWhenPerUserDisabled(): void
    {
        $this->appConfig['per_user_accounts'] = '0';
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice', 'a@b.test', 'Alice'));

        $this->assertNull($this->service()->getCurrentUserApiKey());
    }

    public function testReturnsNullWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->assertNull($this->service()->getCurrentUserApiKey());
    }

    public function testReturnsStoredKeyWithoutProvisioning(): void
    {
        $this->userConfig['alice|synaplan_user_api_key'] = 'sk_alice_existing';
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice', 'a@b.test', 'Alice'));

        $this->httpClient->expects($this->never())->method('post');

        $this->assertSame('sk_alice_existing', $this->service()->getCurrentUserApiKey());
    }

    public function testProvisionsAndMintsOnFirstUse(): void
    {
        // Consent already granted — provisioning is allowed to proceed.
        $this->userConfig['alice|ai_consent'] = '1';
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice', 'alice@example.com', 'Alice'));

        $provisionResp = $this->createMock(IResponse::class);
        $provisionResp->method('getBody')->willReturn(json_encode([
            'success' => true, 'created' => true, 'user' => ['id' => 501],
        ]));
        $mintResp = $this->createMock(IResponse::class);
        $mintResp->method('getBody')->willReturn(json_encode([
            'success' => true, 'api_key' => ['id' => 9, 'key' => 'sk_alice_minted', 'owner_id' => 501],
        ]));

        $captured = [];
        $this->httpClient->expects($this->exactly(2))
            ->method('post')
            ->willReturnCallback(function (string $url, array $options) use (&$captured, $provisionResp, $mintResp): IResponse {
                $captured[] = [$url, json_decode($options['body'], true), $options['headers']['X-API-Key'] ?? null];

                return str_contains($url, '/api-keys') ? $mintResp : $provisionResp;
            });

        $key = $this->service()->getCurrentUserApiKey();

        $this->assertSame('sk_alice_minted', $key);
        // Stored for next time
        $this->assertSame('sk_alice_minted', $this->userConfig['alice|synaplan_user_api_key']);

        // Provision call used the admin key + correct external identity
        $this->assertStringEndsWith('/api/v1/admin/users', $captured[0][0]);
        $this->assertSame('nextcloud', $captured[0][1]['source']);
        $this->assertSame('inst42:alice', $captured[0][1]['external_id']);
        $this->assertSame('alice@example.com', $captured[0][1]['email']);
        $this->assertSame('sk_admin_key', $captured[0][2]);

        // Mint call targeted the provisioned user id
        $this->assertStringEndsWith('/api/v1/admin/users/501/api-keys', $captured[1][0]);
    }

    public function testSynthesizesEmailWhenUserHasNone(): void
    {
        $this->userConfig['bob|ai_consent'] = '1';
        $this->userSession->method('getUser')->willReturn($this->mockUser('bob', null, 'Bob'));

        $provisionResp = $this->createMock(IResponse::class);
        $provisionResp->method('getBody')->willReturn(json_encode(['user' => ['id' => 7]]));
        $mintResp = $this->createMock(IResponse::class);
        $mintResp->method('getBody')->willReturn(json_encode(['api_key' => ['key' => 'sk_bob']]));

        $captured = [];
        $this->httpClient->method('post')->willReturnCallback(
            function (string $url, array $options) use (&$captured, $provisionResp, $mintResp): IResponse {
                $captured[] = json_decode($options['body'], true);

                return str_contains($url, '/api-keys') ? $mintResp : $provisionResp;
            }
        );

        $this->service()->getCurrentUserApiKey();

        $this->assertSame('bob@inst42.nextcloud.local', $captured[0]['email']);
    }

    public function testClearRemovesStoredKey(): void
    {
        $this->userConfig['alice|synaplan_user_api_key'] = 'sk_alice';
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice', 'a@b.test', 'Alice'));

        $this->service()->clearCurrentUserApiKey();

        $this->assertArrayNotHasKey('alice|synaplan_user_api_key', $this->userConfig);
    }

    public function testDoesNotProvisionWithoutConsent(): void
    {
        // Per-user mode on, user present, no stored key, but NO consent.
        $this->userSession->method('getUser')->willReturn($this->mockUser('carol', 'carol@example.com', 'Carol'));

        $this->httpClient->expects($this->never())->method('post');

        $this->assertNull($this->service()->getCurrentUserApiKey());
        $this->assertArrayNotHasKey('carol|synaplan_user_api_key', $this->userConfig);
    }

    public function testConsentRequiredOnlyInPerUserMode(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice', 'a@b.test', 'Alice'));
        $this->assertTrue($this->service()->consentRequired());

        $this->appConfig['per_user_accounts'] = '0';
        $this->assertFalse($this->service()->consentRequired());
    }

    public function testGrantConsentThenProvisions(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('dave', 'dave@example.com', 'Dave'));

        $this->assertFalse($this->service()->hasConsentForCurrentUser());
        $this->service()->grantConsent();
        $this->assertTrue($this->service()->hasConsentForCurrentUser());
        $this->assertSame('1', $this->userConfig['dave|ai_consent']);

        $provisionResp = $this->createMock(IResponse::class);
        $provisionResp->method('getBody')->willReturn(json_encode(['user' => ['id' => 88]]));
        $mintResp = $this->createMock(IResponse::class);
        $mintResp->method('getBody')->willReturn(json_encode(['api_key' => ['key' => 'sk_dave']]));
        $this->httpClient->method('post')->willReturnCallback(
            fn (string $url): IResponse => str_contains($url, '/api-keys') ? $mintResp : $provisionResp
        );

        $this->assertSame('sk_dave', $this->service()->getCurrentUserApiKey());
    }

    public function testRevokeConsentClearsConsentAndKey(): void
    {
        $this->userConfig['alice|ai_consent'] = '1';
        $this->userConfig['alice|synaplan_user_api_key'] = 'sk_alice';
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice', 'a@b.test', 'Alice'));

        $this->service()->revokeConsent();

        $this->assertArrayNotHasKey('alice|ai_consent', $this->userConfig);
        $this->assertArrayNotHasKey('alice|synaplan_user_api_key', $this->userConfig);
    }

    public function testGetSynaplanUserIdForArbitraryUser(): void
    {
        $this->userConfig['erin|synaplan_user_id'] = '77';

        $this->assertSame(77, $this->service()->getSynaplanUserId('erin'));
        $this->assertNull($this->service()->getSynaplanUserId('nobody'));
    }

    public function testDeactivateUserClearsAllPrefsForThatUser(): void
    {
        $this->userConfig['frank|ai_consent'] = '1';
        $this->userConfig['frank|ai_consent_at'] = '2026-07-09T20:00:00+00:00';
        $this->userConfig['frank|synaplan_user_api_key'] = 'sk_frank';

        $this->service()->deactivateUser('frank');

        $this->assertArrayNotHasKey('frank|ai_consent', $this->userConfig);
        $this->assertArrayNotHasKey('frank|ai_consent_at', $this->userConfig);
        $this->assertArrayNotHasKey('frank|synaplan_user_api_key', $this->userConfig);
    }

    public function testDeleteRemoteAccountCallsAdminDelete(): void
    {
        $this->userConfig['gwen|synaplan_user_id'] = '55';

        $resp = $this->createMock(IResponse::class);
        $resp->method('getBody')->willReturn(json_encode(['success' => true]));

        $captured = '';
        $this->httpClient->expects($this->once())
            ->method('delete')
            ->willReturnCallback(function (string $url, array $options) use (&$captured, $resp): IResponse {
                $captured = $url . '|' . ($options['headers']['X-API-Key'] ?? '');

                return $resp;
            });

        $this->service()->deleteRemoteAccount('gwen');

        $this->assertStringEndsWith('/api/v1/admin/users/55|sk_admin_key', $captured);
    }

    public function testDeleteRemoteAccountNoopWhenNoAccount(): void
    {
        $this->httpClient->expects($this->never())->method('delete');

        // No stored synaplan_user_id for this uid.
        $this->service()->deleteRemoteAccount('nobody');
    }

    public function testFetchUsageCallsAdminApi(): void
    {
        $resp = $this->createMock(IResponse::class);
        $resp->method('getBody')->willReturn(json_encode(['success' => true, 'usage' => ['messages' => 9]]));

        $captured = '';
        $this->httpClient->method('get')->willReturnCallback(
            function (string $url, array $options) use (&$captured, $resp): IResponse {
                $captured = $url . '|' . ($options['headers']['X-API-Key'] ?? '');

                return $resp;
            }
        );

        $result = $this->service()->fetchUsage(501);

        $this->assertTrue($result['success']);
        $this->assertStringEndsWith('/api/v1/admin/users/501/usage|sk_admin_key', $captured);
    }
}
