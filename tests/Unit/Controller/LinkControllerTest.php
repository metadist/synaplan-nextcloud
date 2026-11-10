<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use OCA\SynaplanIntegration\Controller\LinkController;
use OCA\SynaplanIntegration\Exception\PlatformLinkException;
use OCA\SynaplanIntegration\Service\OAuthClient;
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
    private OAuthClient&MockObject $oauth;
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
        $this->oauth = $this->createMock(OAuthClient::class);

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
        $this->platformLinks->method('callbackUrl')->willReturn(
            'https://files.example.org/apps/synaplan_integration/link/callback'
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
            $this->oauth,
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
        $this->sessionStore['synaplan_link_verifier'] = 'the-verifier';
    }

    public function testCallbackConnectsWithTheVerifierAndStoresWhoThePersonIs(): void
    {
        $user = $this->user();
        $this->userSession->method('getUser')->willReturn($user);
        $this->primeState('abc123');
        $this->request->method('getParam')->willReturnMap([
            ['code', '', 'auth-code-1'],
            ['state', '', 'abc123'],
        ]);

        $this->oauth->expects($this->once())
            ->method('connect')
            ->with('jdoe', 'auth-code-1', 'the-verifier', 'https://files.example.org/apps/synaplan_integration/link/callback');
        $this->platformLinks->method('whoAmI')->with('jdoe')->willReturn(['id' => 3, 'email' => 'jdoe@example.com']);
        $this->userAccounts->expects($this->once())->method('storeLinkedAccount')
            ->with($user, ['id' => 3, 'email' => 'jdoe@example.com']);
        $this->userAccounts->expects($this->never())->method('provisionAccount');
        $this->userAccounts->expects($this->never())->method('provisionForLinkMode');

        $response = $this->controller()->callback();

        $this->assertStringContainsString('linked=1', $response->getRedirectURL());
        $this->assertArrayNotHasKey('synaplan_link_verifier', $this->sessionStore);
    }

    public function testCallbackWithoutAVerifierDoesNotConnect(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user());
        $this->primeState('abc123');
        unset($this->sessionStore['synaplan_link_verifier']);
        $this->request->method('getParam')->willReturnMap([
            ['code', '', 'c'],
            ['state', '', 'abc123'],
        ]);
        $this->oauth->expects($this->never())->method('connect');

        $response = $this->controller()->callback();

        $this->assertStringContainsString('link_error=exchange', $response->getRedirectURL());
    }

    public function testCallbackAfterTheConsentWasDeniedConnectsNothing(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user());
        $this->primeState('abc123');
        $this->request->method('getParam')->willReturnMap([
            ['code', '', ''],
            ['state', '', 'abc123'],
            ['error', null, 'access_denied'],
        ]);
        $this->oauth->expects($this->never())->method('connect');

        $response = $this->controller()->callback();

        $this->assertStringContainsString('link_error=' . PlatformLinkException::CODE_DENIED, $response->getRedirectURL());
    }

    public function testCallbackReportsWhySynaplanRefused(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user());
        $this->primeState('abc123');
        $this->request->method('getParam')->willReturnMap([
            ['code', '', 'c'],
            ['state', '', 'abc123'],
        ]);
        $this->oauth->method('connect')->willThrowException(
            new PlatformLinkException('unreachable', PlatformLinkException::CODE_UNREACHABLE)
        );
        $this->userAccounts->expects($this->never())->method('storeLinkedAccount');

        $response = $this->controller()->callback();

        $this->assertStringContainsString('link_error=' . PlatformLinkException::CODE_UNREACHABLE, $response->getRedirectURL());
    }

    public function testCallbackRejectsMissingState(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user());
        $this->request->method('getParam')->willReturnMap([
            ['code', '', 'c'],
            ['state', '', ''],
        ]);
        $this->oauth->expects($this->never())->method('connect');

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
        $this->oauth->expects($this->never())->method('connect');

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
        $this->oauth->expects($this->never())->method('connect');
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
        $this->oauth->expects($this->never())->method('connect');

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
        $this->oauth->method('connect')->willThrowException(
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

    public function testStartWhileSynaplanHoldsTheRegistrationForApprovalSaysSo(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user());
        $this->synaplanConfig->method('isLinkAvailable')->willReturn(true);
        $this->platformLinks->method('isWaitingForApproval')->willReturn(true);
        $this->oauth->expects($this->never())->method('authorizeUrl');

        $response = $this->controller()->start();

        $this->assertStringContainsString('link_error=instance_pending', $response->getRedirectURL());
    }

    public function testStartSendsThePersonToAuthorizeAndKeepsTheVerifier(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user());
        $this->synaplanConfig->method('isLinkAvailable')->willReturn(true);
        $this->synaplanConfig->method('isMemoriesEnabled')->willReturn(true);
        $this->oauth->expects($this->once())
            ->method('authorizeUrl')
            ->with(
                'jdoe',
                $this->callback(fn (string $state): bool => strlen($state) === 32),
                'https://files.example.org/apps/synaplan_integration/link/callback',
                true,
            )
            ->willReturn(['url' => 'https://synaplan.example/oauth/authorize?x=1', 'verifier' => 'v-123']);

        $response = $this->controller()->start();

        $this->assertSame('https://synaplan.example/oauth/authorize?x=1', $response->getRedirectURL());
        $this->assertSame('v-123', $this->sessionStore['synaplan_link_verifier']);
        $this->assertSame('jdoe', $this->sessionStore['synaplan_link_uid']);
        $this->assertSame(32, strlen((string) $this->sessionStore['synaplan_link_state']));
    }

    public function testStartReportsWhenSynaplanCannotBeAsked(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user());
        $this->synaplanConfig->method('isLinkAvailable')->willReturn(true);
        $this->oauth->method('authorizeUrl')->willThrowException(
            new PlatformLinkException('unreachable', PlatformLinkException::CODE_UNREACHABLE)
        );

        $response = $this->controller()->start();

        $this->assertStringContainsString('link_error=' . PlatformLinkException::CODE_UNREACHABLE, $response->getRedirectURL());
        $this->assertArrayNotHasKey('synaplan_link_verifier', $this->sessionStore);
    }
}
