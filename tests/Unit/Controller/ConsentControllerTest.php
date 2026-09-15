<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use OCA\SynaplanIntegration\Controller\ConsentController;
use OCA\SynaplanIntegration\Exception\EmailConflictException;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConsentControllerTest extends TestCase
{
    private IRequest&MockObject $request;
    private UserAccountService&MockObject $userAccounts;
    private SynaplanConfig&MockObject $synaplanConfig;
    private IURLGenerator&MockObject $urlGenerator;
    private IUserSession&MockObject $userSession;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->synaplanConfig = $this->createMock(SynaplanConfig::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->userSession = $this->createMock(IUserSession::class);
    }

    private function controller(): ConsentController
    {
        return new ConsentController(
            $this->request,
            $this->userAccounts,
            $this->synaplanConfig,
            $this->urlGenerator,
            $this->userSession,
        );
    }

    public function testCreateAccountConflictDoesNotGrantConsent(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->synaplanConfig->method('isAutoProvisionEnabled')->willReturn(true);
        $this->synaplanConfig->method('isLinkAvailable')->willReturn(true);
        $this->urlGenerator->method('linkToRoute')->willReturn('/link/start');
        $this->userAccounts->expects($this->once())
            ->method('provisionForLinkMode')
            ->willThrowException(new EmailConflictException());
        $this->userAccounts->expects($this->never())->method('grantConsent');

        $response = $this->controller()->setConsent(true, true);
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertTrue($data['conflict']);
    }

    public function testCreateAccountSuccessGrantsConsentAfterProvision(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->synaplanConfig->method('isAutoProvisionEnabled')->willReturn(true);
        $this->userAccounts->expects($this->once())->method('provisionForLinkMode')->with($user);
        $this->userAccounts->expects($this->once())->method('grantConsent');
        $this->userAccounts->method('consentRequired')->willReturn(true);
        $this->userAccounts->method('hasConsentForCurrentUser')->willReturn(true);

        $response = $this->controller()->setConsent(true, true);

        $this->assertTrue($response->getData()['success']);
    }
}
