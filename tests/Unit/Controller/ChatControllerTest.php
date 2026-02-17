<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use OCA\SynaplanIntegration\Controller\ChatController;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ChatControllerTest extends TestCase
{
	private IRequest&MockObject $request;
	private SynaplanClient&MockObject $synaplanClient;
	private IRootFolder&MockObject $rootFolder;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private ChatController $controller;

	protected function setUp(): void
	{
		$this->request = $this->createMock(IRequest::class);
		$this->synaplanClient = $this->createMock(SynaplanClient::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new ChatController(
			$this->request,
			$this->synaplanClient,
			$this->rootFolder,
			$this->userSession,
			$this->logger,
		);
	}

	private function mockUserAndFile(
		string $content = 'Hello world',
		string $mimeType = 'text/plain',
		string $name = 'test.txt',
		int $fileId = 42,
	): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($content);
		$file->method('getMimeType')->willReturn($mimeType);
		$file->method('getName')->willReturn($name);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')
			->with($fileId)
			->willReturn($file);

		$this->rootFolder->method('getUserFolder')
			->with('testuser')
			->willReturn($userFolder);
	}

	public function testChatWithFileContext(): void
	{
		$this->mockUserAndFile('File content here', 'text/plain', 'report.txt');

		$this->synaplanClient->expects($this->once())
			->method('ask')
			->with(
				'What is this file about?',
				'File content here',
				'en',
				null,
				null,
			)
			->willReturn(['summary' => 'This file is about reporting.']);

		$this->synaplanClient->method('getBaseUrl')
			->willReturn('http://localhost:8000');

		$response = $this->controller->chat('What is this file about?', 42);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('This file is about reporting.', $data['response']);
		$this->assertSame('http://localhost:8000', $data['deepLink']);
	}

	public function testChatWithoutFile(): void
	{
		$this->synaplanClient->expects($this->once())
			->method('ask')
			->with('What is AI?', '', 'en', null, null)
			->willReturn(['summary' => 'AI is artificial intelligence.']);

		$this->synaplanClient->method('getBaseUrl')
			->willReturn('http://localhost:8000');

		$response = $this->controller->chat('What is AI?');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('AI is artificial intelligence.', $data['response']);
	}

	public function testChatWithGroupKey(): void
	{
		$this->synaplanClient->expects($this->once())
			->method('ask')
			->with('What is in my docs?', '', 'en', 'RESEARCH', null)
			->willReturn(['summary' => 'Your docs contain research papers.']);

		$this->synaplanClient->method('getBaseUrl')
			->willReturn('http://localhost:8000');

		$response = $this->controller->chat('What is in my docs?', null, 'RESEARCH');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('Your docs contain research papers.', $data['response']);
	}

	public function testChatWithModelId(): void
	{
		$this->synaplanClient->expects($this->once())
			->method('ask')
			->with('Explain AI', '', 'en', null, 42)
			->willReturn(['summary' => 'AI explained by model 42.']);

		$this->synaplanClient->method('getBaseUrl')
			->willReturn('http://localhost:8000');

		$response = $this->controller->chat('Explain AI', null, null, 42);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('AI explained by model 42.', $data['response']);
	}

	public function testChatWithGroupKeyAndModelId(): void
	{
		$this->synaplanClient->expects($this->once())
			->method('ask')
			->with('Summarize docs', '', 'en', 'DEFAULT', 7)
			->willReturn(['summary' => 'Summary of DEFAULT group.']);

		$this->synaplanClient->method('getBaseUrl')
			->willReturn('http://localhost:8000');

		$response = $this->controller->chat('Summarize docs', null, 'DEFAULT', 7);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('Summary of DEFAULT group.', $data['response']);
	}

	public function testChatEmptyMessage(): void
	{
		$response = $this->controller->chat('');

		$this->assertSame(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testChatFileNotFound(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturn(null);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$response = $this->controller->chat('Question?', 999);

		$this->assertSame(404, $response->getStatus());
	}

	public function testChatApiError(): void
	{
		$this->synaplanClient->method('ask')
			->willThrowException(new \RuntimeException('Timeout'));

		$response = $this->controller->chat('Hello');

		$this->assertSame(500, $response->getStatus());
		$this->assertStringContainsString('Timeout', $response->getData()['error']);
	}
}
