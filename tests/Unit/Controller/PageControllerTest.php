<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use OCA\SynaplanIntegration\Controller\PageController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class PageControllerTest extends TestCase
{
	private PageController $controller;

	protected function setUp(): void
	{
		// Util::addScript() triggers addTranslations() → Server::get(IFactory::class)
		// which requires \OC::$server to be set.
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('findLanguage')->willReturn('en');

		$server = $this->createMock(ContainerInterface::class);
		$server->method('get')->willReturn($l10nFactory);
		\OC::$server = $server;

		$request = $this->createMock(IRequest::class);
		$this->controller = new PageController($request);
	}

	protected function tearDown(): void
	{
		\OC::$server = null;
	}

	public function testResearchReturnsTemplateResponse(): void
	{
		$response = $this->controller->research();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('research', $response->getTemplateName());
		$this->assertSame(200, $response->getStatus());
	}
}
