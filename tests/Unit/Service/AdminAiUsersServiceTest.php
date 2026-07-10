<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\AdminAiUsersService;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
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
                    'alice|synaplan_user_api_key' => 'sk_alice',
                    'alice|ai_consent_at' => '2026-07-09T20:00:00+00:00',
                    'bob|synaplan_user_api_key' => '',
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

    public function testDeactivateDelegates(): void
    {
        $this->userAccounts->expects($this->once())
            ->method('deactivateUser')
            ->with('alice');

        $this->service->deactivate('alice');
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
