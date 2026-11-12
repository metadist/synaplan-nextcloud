<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Listener;

use OCA\SynaplanIntegration\Listener\UserDeletedListener;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserDeletedListenerTest extends TestCase
{
    private UserAccountService&MockObject $userAccounts;
    private PlatformLinkService&MockObject $platformLinks;

    protected function setUp(): void
    {
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->platformLinks = $this->createMock(PlatformLinkService::class);
    }

    private function listener(): UserDeletedListener
    {
        return new UserDeletedListener($this->userAccounts, $this->platformLinks);
    }

    private function event(string $uid = 'jdoe'): BeforeUserDeletedEvent
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return new BeforeUserDeletedEvent($user);
    }

    public function testAConnectedPersonIsReportedThenDisconnected(): void
    {
        $order = [];
        $this->userAccounts->method('getLinkKind')->with('jdoe')->willReturn(UserAccountService::KIND_LINKED);
        $this->platformLinks->expects($this->once())->method('reportLifecycle')->with('jdoe', 'deleted')
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'report';
            });
        $this->platformLinks->expects($this->once())->method('disconnectUid')->with('jdoe')
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'disconnect';
            });

        $this->listener()->handle($this->event());

        $this->assertSame(['report', 'disconnect'], $order);
    }

    public function testAProvisionedPersonIsReportedSoSynaplanCanDeleteTheAccount(): void
    {
        $this->userAccounts->method('getLinkKind')->with('jdoe')->willReturn(UserAccountService::KIND_PROVISIONED);
        $this->userAccounts->method('getStoredApiKey')->with('jdoe')->willReturn('sk_jdoe');
        $this->platformLinks->expects($this->once())->method('reportLifecycle')->with('jdoe', 'deleted');
        $this->platformLinks->expects($this->once())->method('disconnectUid')->with('jdoe');

        $this->listener()->handle($this->event());
    }

    public function testAOnceLinkedPersonIsStillReported(): void
    {
        $this->userAccounts->method('wasLinked')->willReturn(true);
        $this->userAccounts->method('getLinkKind')->willReturn(null);
        $this->userAccounts->method('getStoredApiKey')->willReturn('');
        $this->platformLinks->expects($this->once())->method('reportLifecycle')->with('jdoe', 'deleted');

        $this->listener()->handle($this->event());
    }

    public function testAPersonWithNoSynaplanIdentityIsIgnored(): void
    {
        $this->userAccounts->method('getLinkKind')->willReturn(null);
        $this->userAccounts->method('getStoredApiKey')->willReturn('');
        $this->userAccounts->method('getSynaplanUserId')->willReturn(null);
        $this->platformLinks->expects($this->never())->method('reportLifecycle');
        $this->platformLinks->expects($this->never())->method('disconnectUid');

        $this->listener()->handle($this->event());
    }
}
