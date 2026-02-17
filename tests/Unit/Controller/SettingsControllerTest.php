<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Controller\SettingsController;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase
{
	private IRequest&MockObject $request;
	private IConfig&MockObject $config;
	private SynaplanClient&MockObject $synaplanClient;
	private SettingsController $controller;

	protected function setUp(): void
	{
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$this->synaplanClient = $this->createMock(SynaplanClient::class);

		$this->controller = new SettingsController(
			$this->request,
			$this->config,
			$this->synaplanClient,
		);
	}

	public function testGetSettingsReturnsUrlAndMaskedKey(): void
	{
		$this->config->method('getAppValue')->willReturnMap([
			[Application::APP_ID, 'synaplan_url', 'http://localhost:8000', 'https://synaplan.example.com'],
			[Application::APP_ID, 'api_key', '', 'sk_test_abcdef123456'],
		]);

		$response = $this->controller->getSettings();
		$data = $response->getData();

		$this->assertSame('https://synaplan.example.com', $data['synaplan_url']);
		$this->assertTrue($data['api_key_set']);
		// Key should be masked: first 6 + dots + last 4
		$this->assertStringStartsWith('sk_tes', $data['api_key_masked']);
		$this->assertStringEndsWith('3456', $data['api_key_masked']);
		$this->assertStringContainsString('•', $data['api_key_masked']);
	}

	public function testGetSettingsWithNoApiKey(): void
	{
		$this->config->method('getAppValue')->willReturnMap([
			[Application::APP_ID, 'synaplan_url', 'http://localhost:8000', 'http://localhost:8000'],
			[Application::APP_ID, 'api_key', '', ''],
		]);

		$response = $this->controller->getSettings();
		$data = $response->getData();

		$this->assertSame('http://localhost:8000', $data['synaplan_url']);
		$this->assertFalse($data['api_key_set']);
		$this->assertSame('', $data['api_key_masked']);
	}

	public function testSaveSettingsPersistsValues(): void
	{
		$this->config->expects($this->exactly(2))
			->method('setAppValue')
			->willReturnCallback(function (string $app, string $key, string $value): void {
				match ($key) {
					'synaplan_url' => $this->assertSame('https://new-url.com', $value),
					'api_key' => $this->assertSame('sk_new_key', $value),
					default => $this->fail("Unexpected key: {$key}"),
				};
			});

		$response = $this->controller->saveSettings(
			synaplan_url: 'https://new-url.com/',
			api_key: 'sk_new_key',
		);
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	public function testSaveSettingsSkipsEmptyApiKey(): void
	{
		$this->config->expects($this->once())
			->method('setAppValue')
			->with(Application::APP_ID, 'synaplan_url', 'https://url.com');

		$response = $this->controller->saveSettings(
			synaplan_url: 'https://url.com',
		);

		$this->assertTrue($response->getData()['success']);
	}

	public function testTestConnectionSuccess(): void
	{
		$this->synaplanClient->method('healthCheck')
			->willReturn([
				'status' => 'ok',
				'providers' => ['openai' => true],
			]);

		$response = $this->controller->testConnection();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('ok', $data['status']);
		$this->assertTrue($data['providers']['openai']);
	}

	public function testTestConnectionFailure(): void
	{
		$this->synaplanClient->method('healthCheck')
			->willThrowException(new \Exception('Connection refused'));

		$response = $this->controller->testConnection();
		$data = $response->getData();

		$this->assertFalse($data['success']);
		$this->assertSame('Connection refused', $data['error']);
		$this->assertSame(200, $response->getStatus());
	}
}
