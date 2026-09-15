<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Listener;

use OCA\SynaplanIntegration\Listener\UserDeletedListener;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserDeletedListenerTest extends TestCase
{
    private UserAccountService&MockObject $userAccounts;
    private SynaplanConfig&MockObject $synaplanConfig;
    private PlatformLinkService&MockObject $platformLinks;

    protected function setUp(): void
    {
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->synaplanConfig = $this->createMock(SynaplanConfig::class);
        $this->platformLinks = $this->createMock(PlatformLinkService::class);
    }

    private function listener(): UserDeletedListener
    {
        return new UserDeletedListener(
            $this->userAccounts,
            $this->synaplanConfig,
            $this->platformLinks,
        );
    }

    private function event(string $uid = 'jdoe'): BeforeUserDeletedEvent
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return new BeforeUserDeletedEvent($user);
    }

    public function testLinkedUserIsDisconnectedInSharedMode(): void
    {
        $this->synaplanConfig->method('isPerUserAccountsEnabled')->willReturn(false);
        $this->userAccounts->method('wasLinked')->with('jdoe')->willReturn(true);
        $this->platformLinks->expects($this->once())->method('disconnectUid')->with('jdoe');
        $this->userAccounts->expects($this->never())->method('deleteRemoteAccount');

        $this->listener()->handle($this->event());
    }

    public function testOnceLinkedUserIsNotDeletedAfterDisconnect(): void
    {
        $this->synaplanConfig->method('isPerUserAccountsEnabled')->willReturn(true);
        $this->userAccounts->method('wasLinked')->with('jdoe')->willReturn(true);
        $this->userAccounts->method('getLinkKind')->with('jdoe')->willReturn(null);
        $this->platformLinks->expects($this->once())->method('disconnectUid')->with('jdoe');
        $this->userAccounts->expects($this->never())->method('deleteRemoteAccount');

        $this->listener()->handle($this->event());
    }

    public function testProvisionedUserDeletesRemoteAccount(): void
    {
        $this->synaplanConfig->method('isPerUserAccountsEnabled')->willReturn(true);
        $this->userAccounts->method('wasLinked')->with('jdoe')->willReturn(false);
        $this->userAccounts->method('getLinkKind')->with('jdoe')->willReturn(UserAccountService::KIND_PROVISIONED);
        $this->platformLinks->expects($this->never())->method('disconnectUid');
        $this->userAccounts->expects($this->once())->method('deleteRemoteAccount')->with('jdoe');

        $this->listener()->handle($this->event());
    }
}
