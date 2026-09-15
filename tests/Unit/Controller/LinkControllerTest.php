<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use OCA\SynaplanIntegration\Controller\LinkController;
use OCA\SynaplanIntegration\Exception\PlatformLinkException;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LinkControllerTest extends TestCase
{
    private IRequest&MockObject $request;
    private ISession&MockObject $session;
    private IUserSession&MockObject $userSession;
    private SynaplanConfig&MockObject $synaplanConfig;
    private UserAccountService&MockObject $userAccounts;
    private PlatformLinkService&MockObject $platformLinks;
    /** @var array<string, mixed> */
    private array $sessionStore = [];

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->session = $this->createMock(ISession::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->synaplanConfig = $this->createMock(SynaplanConfig::class);
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->platformLinks = $this->createMock(PlatformLinkService::class);
        $this->sessionStore = [];

        $this->session->method('get')->willReturnCallback(
            fn (string $key) => $this->sessionStore[$key] ?? null
        );
        $this->session->method('set')->willReturnCallback(
            function (string $key, mixed $value): void {
                $this->sessionStore[$key] = $value;
            }
        );
        $this->session->method('remove')->willReturnCallback(
            function (string $key): void {
                unset($this->sessionStore[$key]);
            }
        );

        $this->platformLinks->method('personalSettingsUrl')->willReturnCallback(
            fn (array $query = []) => 'https://files.example.org/settings/user/synaplan_integration'
                . ($query !== [] ? '?' . http_build_query($query) : '')
        );
        $this->platformLinks->method('connectUrl')->willReturn(
            'https://synaplan.example/connect/platform?client=nextcloud'
        );
    }

    private function controller(): LinkController
    {
        return new LinkController(
            $this->request,
            $this->session,
            $this->userSession,
            $this->synaplanConfig,
            $this->userAccounts,
            $this->platformLinks,
        );
    }

    private function user(string $uid = 'jdoe'): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return $user;
    }

    private function primeState(string $state, int $ageSeconds = 0, string $uid = 'jdoe'): void
    {
        $this->sessionStore['synaplan_link_state'] = $state;
        $this->sessionStore['synaplan_link_state_at'] = time() - $ageSeconds;
        $this->sessionStore['synaplan_link_uid'] = $uid;
    }

    public function testCallbackNeverCallsAdminUsersApi(): void
    {
        $user = $this->user();
        $this->userSession->method('getUser')->willReturn($user);
        $this->primeState('abc123');
        $this->request->method('getParam')->willReturnMap([
            ['code', '', 'link-code-1'],
            ['state', '', 'abc123'],
        ]);

        $this->platformLinks->expects($this->once())
            ->method('exchange')
            ->with('link-code-1')
            ->willReturn([
                'api_key' => ['id' => 9, 'key' => 'sk_linked'],
                'user' => ['id' => 3, 'email' => 'jdoe@example.com'],
                'link_id' => 11,
            ]);
        $this->userAccounts->expects($this->once())->method('storeLinkedAccount');
        $this->userAccounts->expects($this->never())->method('provisionAccount');
        $this->userAccounts->expects($this->never())->method('provisionForLinkMode');

        $response = $this->controller()->callback();

        $this->assertStringContainsString('linked=1', $response->getRedirectURL());
    }

    public function testCallbackRejectsMissingState(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user());
        $this->request->method('getParam')->willReturnMap([
            ['code', '', 'c'],
            ['state', '', ''],
        ]);
        $this->platformLinks->expects($this->never())->method('exchange');

        $response = $this->controller()->callback();

        $this->assertStringContainsString('link_error=state', $response->getRedirectURL());
    }

    public function testCallbackRejectsForeignState(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user());
        $this->primeState('expected');
        $this->request->method('getParam')->willReturnMap([
            ['code', '', 'c'],
            ['state', '', 'other'],
        ]);
        $this->platformLinks->expects($this->never())->method('exchange');

        $response = $this->controller()->callback();

        $this->assertStringContainsString('link_error=state', $response->getRedirectURL());
        $this->assertArrayNotHasKey('synaplan_link_state', $this->sessionStore);
    }

    public function testCallbackRejectsUidMismatch(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user('bob'));
        $this->primeState('abc123', 0, 'alice');
        $this->request->method('getParam')->willReturnMap([
            ['code', '', 'c'],
            ['state', '', 'abc123'],
        ]);
        $this->platformLinks->expects($this->never())->method('exchange');
        $this->userAccounts->expects($this->never())->method('storeLinkedAccount');

        $response = $this->controller()->callback();

        $this->assertStringContainsString('link_error=state', $response->getRedirectURL());
        $this->assertArrayNotHasKey('synaplan_link_state', $this->sessionStore);
        $this->assertArrayNotHasKey('synaplan_link_uid', $this->sessionStore);
    }

    public function testCallbackRejectsStaleState(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user());
        $this->primeState('abc123', 601);
        $this->request->method('getParam')->willReturnMap([
            ['code', '', 'c'],
            ['state', '', 'abc123'],
        ]);
        $this->platformLinks->expects($this->never())->method('exchange');

        $response = $this->controller()->callback();

        $this->assertStringContainsString('link_error=state', $response->getRedirectURL());
    }

    public function testStateIsSingleUse(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user());
        $this->primeState('once');
        $this->request->method('getParam')->willReturnMap([
            ['code', '', 'c'],
            ['state', '', 'once'],
        ]);
        $this->platformLinks->method('exchange')->willThrowException(
            new PlatformLinkException('fail', PlatformLinkException::CODE_EXCHANGE)
        );

        $this->controller()->callback();
        $second = $this->controller()->callback();

        $this->assertStringContainsString('link_error=state', $second->getRedirectURL());
    }

    public function testDisconnectRevokesOwnKeyThenClearsPrefs(): void
    {
        $user = $this->user();
        $this->userSession->method('getUser')->willReturn($user);
        $this->platformLinks->expects($this->once())->method('disconnect')->with($user);

        $response = $this->controller()->disconnect();

        $this->assertTrue($response->getData()['success']);
    }

    public function testDisconnectKeepsLinkWhenRevokeFails(): void
    {
        $user = $this->user();
        $this->userSession->method('getUser')->willReturn($user);
        $this->platformLinks->expects($this->once())
            ->method('disconnect')
            ->willThrowException(new PlatformLinkException('fail', PlatformLinkException::CODE_EXCHANGE));

        $response = $this->controller()->disconnect();

        $this->assertFalse($response->getData()['success']);
        $this->assertSame(502, $response->getStatus());
    }

    public function testStartRedirectsWhenInstanceMissing(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user());
        $this->synaplanConfig->method('isLinkAvailable')->willReturn(false);

        $response = $this->controller()->start();

        $this->assertStringContainsString('link_error=instance_pending', $response->getRedirectURL());
    }
}
