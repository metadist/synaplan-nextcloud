<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\AdminAiUsersService;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminAiUsersServiceTest extends TestCase
{
    private IConfig&MockObject $config;
    private IUserManager&MockObject $userManager;
    private UserAccountService&MockObject $userAccounts;
    private AdminAiUsersService $service;

    protected function setUp(): void
    {
        $this->config = $this->createMock(IConfig::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userAccounts = $this->createMock(UserAccountService::class);

        $this->service = new AdminAiUsersService(
            $this->config,
            $this->userManager,
            $this->userAccounts,
        );
    }

    public function testListActivatedUsers(): void
    {
        $this->config->method('getUsersForUserValue')
            ->with('synaplan_integration', 'ai_consent', '1')
            ->willReturn(['bob', 'alice']);

        $this->config->method('getUserValue')->willReturnCallback(
            function (string $uid, string $app, string $key, string $default = '') {
                $store = [
                    'alice|ai_consent_at' => '2026-07-09T20:00:00+00:00',
                    'bob|ai_consent_at' => '',
                ];

                return $store[$uid . '|' . $key] ?? $default;
            }
        );

        $alice = $this->createMock(IUser::class);
        $alice->method('getDisplayName')->willReturn('Alice Example');
        $alice->method('getEMailAddress')->willReturn('alice@example.com');
        $this->userManager->method('get')->willReturnCallback(
            fn (string $uid): ?IUser => $uid === 'alice' ? $alice : null
        );

        $this->userAccounts->method('getSynaplanUserId')->willReturnCallback(
            fn (string $uid): ?int => $uid === 'alice' ? 501 : null
        );
        $this->userAccounts->method('getStoredApiKey')->willReturnCallback(
            fn (string $uid): string => $uid === 'alice' ? 'sk_alice' : ''
        );

        $users = $this->service->listActivatedUsers();

        // Sorted by uid: alice, bob
        $this->assertCount(2, $users);
        $this->assertSame('alice', $users[0]['uid']);
        $this->assertSame('Alice Example', $users[0]['displayName']);
        $this->assertSame('alice@example.com', $users[0]['email']);
        $this->assertSame(501, $users[0]['synaplanUserId']);
        $this->assertTrue($users[0]['hasKey']);

        // bob has no display name (user object null) → falls back to uid, no key
        $this->assertSame('bob', $users[1]['uid']);
        $this->assertSame('bob', $users[1]['displayName']);
        $this->assertNull($users[1]['synaplanUserId']);
        $this->assertFalse($users[1]['hasKey']);
    }

    public function testListReportsKindLinkedOrProvisioned(): void
    {
        $this->config->method('getUsersForUserValue')->willReturn(['alice', 'bob']);
        $this->config->method('getUserValue')->willReturnCallback(
            function (string $uid, string $app, string $key, string $default = '') {
                $store = [
                    'alice|synaplan_user_api_key' => 'sk_a',
                    'bob|synaplan_user_api_key' => 'sk_b',
                ];

                return $store[$uid . '|' . $key] ?? $default;
            }
        );
        $this->userManager->method('get')->willReturn(null);
        $this->userAccounts->method('getSynaplanUserId')->willReturn(1);
        $this->userAccounts->method('getLinkKind')->willReturnCallback(
            fn (string $uid): string => $uid === 'alice' ? 'linked' : 'provisioned'
        );

        $users = $this->service->listActivatedUsers();

        $this->assertSame('linked', $users[0]['kind']);
        $this->assertSame('provisioned', $users[1]['kind']);
    }

    public function testDeactivateDelegates(): void
    {
        $this->userAccounts->expects($this->once())
            ->method('deactivateUser')
            ->with('alice');

        $this->service->deactivate('alice');
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function kinds(): iterable
    {
        yield 'connected their account' => [UserAccountService::KIND_LINKED];
        yield 'account created by the app' => [UserAccountService::KIND_PROVISIONED];
        yield 'no key yet' => [null];
    }

    #[DataProvider('kinds')]
    public function testDeactivateRevokesTheKeyInSynaplanForEveryone(?string $kind): void
    {
        $this->userAccounts->method('getLinkKind')->willReturn($kind);
        $this->userAccounts->expects($this->never())->method('deactivateUser');
        $platformLinks = $this->createMock(PlatformLinkService::class);
        $platformLinks->expects($this->once())->method('disconnectUid')->with('alice');
        $service = new AdminAiUsersService($this->config, $this->userManager, $this->userAccounts, $platformLinks);

        $service->deactivate('alice');
    }

    public function testUsageWithoutAccountReturnsError(): void
    {
        $this->userAccounts->method('getSynaplanUserId')->willReturn(null);

        $result = $this->service->usage('alice');

        $this->assertFalse($result['success']);
    }

    public function testUsageProxiesToSynaplan(): void
    {
        $this->userAccounts->method('getSynaplanUserId')->willReturn(501);
        $this->userAccounts->method('fetchUsage')
            ->with(501)
            ->willReturn(['success' => true, 'usage' => ['messages' => 42]]);

        $result = $this->service->usage('alice');

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['usage']['messages']);
    }
}
