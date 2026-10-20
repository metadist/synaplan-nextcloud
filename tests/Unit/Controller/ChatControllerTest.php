<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use OCA\SynaplanIntegration\Controller\ChatController;
use OCA\SynaplanIntegration\Service\LanguageService;
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
    private LanguageService&MockObject $languageService;
    private LoggerInterface&MockObject $logger;
    private ChatController $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->synaplanClient = $this->createMock(SynaplanClient::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->languageService = $this->createMock(LanguageService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Default: resolve to English so the existing assertions stay stable.
        $this->languageService->method('resolveLanguage')->willReturn('en');
        $this->languageService->method('getLanguageName')->willReturn('English');

        $this->controller = new ChatController(
            $this->request,
            $this->synaplanClient,
            $this->rootFolder,
            $this->userSession,
            $this->languageService,
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

    public function testChatAnswersTheQuestionAboutTheFile(): void
    {
        $this->mockUserAndFile('File content here', 'text/plain', 'report.txt');

        $this->synaplanClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(fn (array $messages): bool => $messages[0]['role'] === 'system'
                    && str_contains($messages[0]['content'], 'always answer in English')
                    && str_contains($messages[1]['content'], "--- CONTEXT ---\nFile content here")
                    && str_contains($messages[1]['content'], 'Question: What is this file about?')),
                null,
            )
            ->willReturn('This file is about reporting.');
        $this->synaplanClient->method('getPublicBaseUrl')->willReturn('https://synaplan.example.org');

        $data = $this->controller->chat('What is this file about?', 42)->getData();

        $this->assertTrue($data['success']);
        $this->assertSame('This file is about reporting.', $data['response']);
        $this->assertSame('https://synaplan.example.org', $data['deepLink']);
    }

    public function testChatWithoutFileSendsTheQuestionAlone(): void
    {
        $this->synaplanClient->expects($this->once())
            ->method('chat')
            ->with($this->callback(fn (array $messages): bool => $messages[1] === ['role' => 'user', 'content' => 'What is AI?']), null)
            ->willReturn('AI is artificial intelligence.');

        $this->assertSame('AI is artificial intelligence.', $this->controller->chat('What is AI?')->getData()['response']);
    }

    public function testChatWithGroupKeyAnswersFromTheFolder(): void
    {
        $this->synaplanClient->expects($this->once())
            ->method('searchRagContext')
            ->with('What is in my docs?', 'RESEARCH')
            ->willReturn('A chunk about research papers.');
        $this->synaplanClient->expects($this->once())
            ->method('chat')
            ->with($this->callback(fn (array $messages): bool => str_contains($messages[1]['content'], 'A chunk about research papers.')), null)
            ->willReturn('Your docs contain research papers.');

        $data = $this->controller->chat('What is in my docs?', null, 'RESEARCH')->getData();

        $this->assertSame('Your docs contain research papers.', $data['response']);
    }

    public function testChatPassesTheChosenModel(): void
    {
        $this->synaplanClient->expects($this->once())
            ->method('chat')
            ->with($this->anything(), 'gpt-oss-120b')
            ->willReturn('Explained.');

        $this->assertSame('Explained.', $this->controller->chat('Explain AI', null, null, 'gpt-oss-120b')->getData()['response']);
    }

    public function testChatWithMemoriesPutsThemInTheSystemPrompt(): void
    {
        $this->synaplanClient->expects($this->once())
            ->method('searchMemoryContext')
            ->with('What do I prefer?')
            ->willReturn('- diet: vegetarian');
        $this->synaplanClient->expects($this->once())
            ->method('chat')
            ->with($this->callback(fn (array $messages): bool => str_contains($messages[0]['content'], '- diet: vegetarian')
                && $messages[1]['content'] === 'What do I prefer?'), null)
            ->willReturn('You prefer vegetarian food.');

        $data = $this->controller->chat('What do I prefer?', null, null, null, true)->getData();

        $this->assertSame('You prefer vegetarian food.', $data['response']);
    }

    public function testChatWithoutMemoriesSkipsMemorySearch(): void
    {
        $this->synaplanClient->expects($this->never())
            ->method('searchMemoryContext');
        $this->synaplanClient->method('chat')->willReturn('ok');

        $this->assertTrue($this->controller->chat('Hi')->getData()['success']);
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

    public function testChatApiErrorKeepsTheExceptionInTheLog(): void
    {
        $this->synaplanClient->method('chat')
            ->willThrowException(new \RuntimeException('Timeout at http://backend/v1/chat/completions'));
        $this->logger->expects($this->once())->method('error');

        $response = $this->controller->chat('Hello');

        $this->assertSame(500, $response->getStatus());
        $this->assertSame(['success' => false, 'error' => 'unexpected'], $response->getData());
    }
}
