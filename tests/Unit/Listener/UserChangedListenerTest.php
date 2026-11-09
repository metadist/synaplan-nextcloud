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
        $this->userAccounts->method('getSynaplanUserId')->willReturn(9);
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

    public function testDisableReportsLifecycle(): void
    {
        $this->platformLinks->expects($this->once())->method('reportLifecycle')->with('ada', 'disabled');

        $this->listener()->handle($this->event('enabled', false, true));
    }

    public function testEnableReportsLifecycle(): void
    {
        $this->platformLinks->expects($this->once())->method('reportLifecycle')->with('ada', 'enabled');

        $this->listener()->handle($this->event('enabled', true, false));
    }

    public function testOtherChangesAreIgnored(): void
    {
        $this->platformLinks->expects($this->never())->method('reportLifecycle');

        $this->listener()->handle($this->event('displayName', 'Ada', 'Ada Example'));
    }

    public function testAPersonWithNoIdentityIsIgnored(): void
    {
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->userAccounts->method('wasLinked')->willReturn(false);
        $this->userAccounts->method('getLinkKind')->willReturn(null);
        $this->userAccounts->method('getStoredApiKey')->willReturn('');
        $this->userAccounts->method('getSynaplanUserId')->willReturn(null);
        $this->platformLinks->expects($this->never())->method('reportLifecycle');

        $this->listener()->handle($this->event('enabled', false, true));
    }
}
