<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Listener;

use OCA\SynaplanIntegration\Listener\UserChangedListener;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\IUser;
use OCP\User\Events\UserChangedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserChangedListenerTest extends TestCase
{
    private UserAccountService&MockObject $userAccounts;
    private PlatformLinkService&MockObject $platformLinks;

    protected function setUp(): void
    {
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->platformLinks = $this->createMock(PlatformLinkService::class);
    }

    private function listener(): UserChangedListener
    {
        return new UserChangedListener($this->userAccounts, $this->platformLinks);
    }

    private function event(string $feature, mixed $value, mixed $old = null): UserChangedEvent
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('ada');

        return new UserChangedEvent($user, $feature, $value, $old);
    }

    public function testDisablingAConnectedPersonEndsTheirLink(): void
    {
        $this->userAccounts->method('getLinkKind')->with('ada')->willReturn(UserAccountService::KIND_LINKED);
        $this->platformLinks->expects($this->once())->method('disconnectUid')->with('ada');

        $this->listener()->handle($this->event('enabled', false, true));
    }

    public function testEnablingLeavesTheLinkAlone(): void
    {
        $this->userAccounts->method('getLinkKind')->willReturn(UserAccountService::KIND_LINKED);
        $this->platformLinks->expects($this->never())->method('disconnectUid');

        $this->listener()->handle($this->event('enabled', true, false));
    }

    public function testAnUnchangedStateIsIgnored(): void
    {
        $this->userAccounts->expects($this->never())->method('getLinkKind');
        $this->platformLinks->expects($this->never())->method('disconnectUid');

        $this->listener()->handle($this->event('enabled', false, false));
    }

    public function testOtherChangesAreIgnored(): void
    {
        $this->userAccounts->expects($this->never())->method('getLinkKind');
        $this->platformLinks->expects($this->never())->method('disconnectUid');

        $this->listener()->handle($this->event('displayName', 'Ada', 'Ada Example'));
    }

    public function testDisablingAProvisionedPersonKeepsTheirKey(): void
    {
        $this->userAccounts->method('getLinkKind')->willReturn(UserAccountService::KIND_PROVISIONED);
        $this->platformLinks->expects($this->never())->method('disconnectUid');

        $this->listener()->handle($this->event('enabled', false, true));
    }

    public function testAPersonWithNoIdentityIsIgnored(): void
    {
        $this->userAccounts->method('getLinkKind')->willReturn(null);
        $this->platformLinks->expects($this->never())->method('disconnectUid');

        $this->listener()->handle($this->event('enabled', false, true));
    }
}
