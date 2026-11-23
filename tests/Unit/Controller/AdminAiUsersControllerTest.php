<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use OCA\SynaplanIntegration\Controller\AdminAiUsersController;
use OCA\SynaplanIntegration\Exception\FailureException;
use OCA\SynaplanIntegration\Http\Failure;
use OCA\SynaplanIntegration\Service\AdminAiUsersService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class AdminAiUsersControllerTest extends TestCase
{
    public function testDeactivateSaysWhenSynaplanCouldNotPauseThePerson(): void
    {
        $aiUsers = $this->createMock(AdminAiUsersService::class);
        $aiUsers->method('deactivate')->willThrowException(
            new FailureException(new Failure(502, Failure::SYNAPLAN_UNREACHABLE), 'Synaplan could not pause the person'),
        );
        $controller = new AdminAiUsersController($this->createMock(IRequest::class), $aiUsers);

        $response = $controller->deactivate('carol');

        $this->assertSame(502, $response->getStatus());
        $this->assertSame(Failure::SYNAPLAN_UNREACHABLE, $response->getData()['error']);
    }

    public function testDeactivateAnswersSuccess(): void
    {
        $aiUsers = $this->createMock(AdminAiUsersService::class);
        $aiUsers->expects($this->once())->method('deactivate')->with('carol');
        $controller = new AdminAiUsersController($this->createMock(IRequest::class), $aiUsers);

        $this->assertSame(['success' => true], $controller->deactivate('carol')->getData());
    }
}
