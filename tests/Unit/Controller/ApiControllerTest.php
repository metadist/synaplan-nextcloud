<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use OCA\SynaplanIntegration\Controller\ApiController;
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

class ApiControllerTest extends TestCase
{
	private IRequest&MockObject $request;
	private SynaplanClient&MockObject $synaplanClient;
	private IRootFolder&MockObject $rootFolder;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private ApiController $controller;

	protected function setUp(): void
	{
		$this->request = $this->createMock(IRequest::class);
		$this->synaplanClient = $this->createMock(SynaplanClient::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new ApiController(
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
		int $size = 11,
		int $fileId = 42,
	): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($content);
		$file->method('getMimeType')->willReturn($mimeType);
		$file->method('getSize')->willReturn($size);
		$file->method('getName')->willReturn('testfile.txt');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')
			->with($fileId)
			->willReturn($file);

		$this->rootFolder->method('getUserFolder')
			->with('testuser')
			->willReturn($userFolder);
	}

	// ── Summarize ────────────────────────────────────────────────

	public function testSummarizeSuccess(): void
	{
		$this->mockUserAndFile('Lorem ipsum dolor sit amet');

		$this->synaplanClient->expects($this->once())
			->method('summarize')
			->with('Lorem ipsum dolor sit amet', 'bullet-points', 'medium', 'en')
			->willReturn([
				'success' => true,
				'summary' => '• Lorem ipsum',
				'metadata' => ['model' => 'gpt-4'],
			]);

		$response = $this->controller->summarize(42);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('• Lorem ipsum', $data['summary']);
		$this->assertSame(200, $response->getStatus());
	}

	public function testSummarizeWithOptions(): void
	{
		$this->mockUserAndFile('Some text content');

		$this->synaplanClient->expects($this->once())
			->method('summarize')
			->with('Some text content', 'abstractive', 'short', 'de');

		$this->synaplanClient->method('summarize')
			->willReturn(['success' => true, 'summary' => 'Zusammenfassung']);

		$this->controller->summarize(42, 'abstractive', 'short', 'de');
	}

	public function testSummarizeFileNotFound(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturn(null);

		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$response = $this->controller->summarize(999);

		$this->assertSame(404, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testSummarizeNotAuthenticated(): void
	{
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->summarize(42);

		$this->assertSame(404, $response->getStatus());
	}

	public function testSummarizeUnsupportedMimeType(): void
	{
		$this->mockUserAndFile('binary', 'image/png', 1024);

		$response = $this->controller->summarize(42);

		$this->assertSame(500, $response->getStatus());
		$this->assertStringContainsString('Unsupported file type', $response->getData()['error']);
	}

	public function testSummarizeFileTooLarge(): void
	{
		$this->mockUserAndFile('big', 'text/plain', 10 * 1024 * 1024);

		$response = $this->controller->summarize(42);

		$this->assertSame(500, $response->getStatus());
		$this->assertStringContainsString('too large', $response->getData()['error']);
	}

	public function testSummarizeApiError(): void
	{
		$this->mockUserAndFile('Some content');

		$this->synaplanClient->method('summarize')
			->willThrowException(new \RuntimeException('API timeout'));

		$response = $this->controller->summarize(42);

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('API timeout', $response->getData()['error']);
	}

	// ── Translate ────────────────────────────────────────────────

	public function testTranslateSuccess(): void
	{
		$this->mockUserAndFile('Hello world');

		$this->synaplanClient->expects($this->once())
			->method('summarize')
			->with('Hello world', 'abstractive', 'long', 'de')
			->willReturn([
				'success' => true,
				'summary' => 'Hallo Welt',
			]);

		$response = $this->controller->translate(42, 'de');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('Hallo Welt', $data['translation']);
		$this->assertSame(200, $response->getStatus());
	}

	public function testTranslateFileNotFound(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturn(null);

		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$response = $this->controller->translate(999, 'fr');

		$this->assertSame(404, $response->getStatus());
	}

	public function testTranslateApiError(): void
	{
		$this->mockUserAndFile('Some content');

		$this->synaplanClient->method('summarize')
			->willThrowException(new \RuntimeException('Rate limited'));

		$response = $this->controller->translate(42, 'es');

		$this->assertSame(500, $response->getStatus());
		$this->assertStringContainsString('Rate limited', $response->getData()['error']);
	}

	// ── Upload to Knowledge ─────────────────────────────────────

	public function testUploadToKnowledgeSuccess(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('Hello world');
		$file->method('getName')->willReturn('test.txt');
		$file->method('getSize')->willReturn(11);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')
			->with(42)
			->willReturn($file);

		$this->rootFolder->method('getUserFolder')
			->with('testuser')
			->willReturn($userFolder);

		$this->synaplanClient->expects($this->once())
			->method('uploadFile')
			->with('test.txt', 'Hello world', 'TESTGROUP')
			->willReturn([
				'success' => true,
				'files' => [
					[
						'chunks_created' => 1,
						'vectorized' => true,
						'extracted_text_length' => 11,
					],
				],
			]);

		$response = $this->controller->uploadToKnowledge(42, 'TESTGROUP');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('test.txt', $data['filename']);
		$this->assertSame('TESTGROUP', $data['groupKey']);
		$this->assertSame(1, $data['chunksCreated']);
		$this->assertTrue($data['vectorized']);
	}

	public function testUploadToKnowledgeFileNotFound(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturn(null);

		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$response = $this->controller->uploadToKnowledge(999, 'DEFAULT');

		$this->assertSame(404, $response->getStatus());
	}

	// ── Get Groups ──────────────────────────────────────────────

	public function testGetGroupsSuccess(): void
	{
		$this->synaplanClient->expects($this->once())
			->method('getFileGroups')
			->willReturn([
				'success' => true,
				'groups' => [
					['name' => 'DEFAULT', 'count' => 5],
					['name' => 'RESEARCH', 'count' => 3],
				],
			]);

		$response = $this->controller->getGroups();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertCount(2, $data['groups']);
		$this->assertSame('DEFAULT', $data['groups'][0]['name']);
	}

	public function testGetGroupsApiError(): void
	{
		$this->synaplanClient->method('getFileGroups')
			->willThrowException(new \RuntimeException('Connection refused'));

		$response = $this->controller->getGroups();

		$this->assertSame(500, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	// ── Get Models ──────────────────────────────────────────────

	public function testGetModelsSuccess(): void
	{
		$this->synaplanClient->expects($this->once())
			->method('getModels')
			->willReturn([
				'success' => true,
				'models' => [
					'CHAT' => [
						[
							'id' => 1,
							'name' => 'gpt-4',
							'service' => 'OpenAI',
							'providerId' => 'gpt-4',
						],
						[
							'id' => 2,
							'name' => 'Claude 3',
							'service' => 'Anthropic',
							'providerId' => 'claude-3',
						],
					],
					'VECTORIZE' => [
						['id' => 3, 'name' => 'text-embedding', 'service' => 'OpenAI', 'providerId' => 'text-embedding-3-small'],
					],
				],
			]);

		$response = $this->controller->getModels();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		// Only CHAT models should be returned
		$this->assertCount(2, $data['models']);
		$this->assertSame(1, $data['models'][0]['id']);
		$this->assertSame('gpt-4', $data['models'][0]['name']);
		$this->assertSame('OpenAI', $data['models'][0]['service']);
	}

	public function testGetModelsApiError(): void
	{
		$this->synaplanClient->method('getModels')
			->willThrowException(new \RuntimeException('API unavailable'));

		$response = $this->controller->getModels();

		$this->assertSame(500, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}
}
