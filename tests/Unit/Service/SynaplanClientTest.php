<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SynaplanClientTest extends TestCase
{
    private IClientService&MockObject $clientService;
    private IClient&MockObject $httpClient;
    private IConfig&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private SynaplanClient $synaplanClient;

    protected function setUp(): void
    {
        $this->clientService = $this->createMock(IClientService::class);
        $this->httpClient = $this->createMock(IClient::class);
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->clientService->method('newClient')->willReturn($this->httpClient);
        $this->config->method('getAppValue')->willReturnMap([
            [Application::APP_ID, 'synaplan_url', 'http://localhost:8000', 'http://localhost:8000'],
            [Application::APP_ID, 'api_key', '', 'sk_test_key_123'],
        ]);

        $this->synaplanClient = new SynaplanClient(
            $this->clientService,
            $this->config,
            $this->logger,
        );
    }

    public function testHealthCheckReturnsStatus(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'status' => 'ok',
            'providers' => ['openai' => true, 'ollama' => false],
        ]));

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with(
                'http://localhost:8000/api/health',
                $this->callback(function (array $options): bool {
                    return $options['headers']['X-API-Key'] === 'sk_test_key_123'
                        && $options['headers']['Accept'] === 'application/json';
                })
            )
            ->willReturn($response);

        $result = $this->synaplanClient->healthCheck();

        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['providers']['openai']);
        $this->assertFalse($result['providers']['ollama']);
    }

    public function testSummarizeCallsCorrectEndpoint(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'success' => true,
            'summary' => '• Point 1\n• Point 2',
        ]));

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with(
                'http://localhost:8000/api/v1/summary/generate',
                $this->callback(function (array $options): bool {
                    $body = json_decode($options['body'], true);

                    return $body['text'] === 'Test document text'
                        && $body['summaryType'] === 'bullet-points'
                        && $body['length'] === 'short'
                        && $body['outputLanguage'] === 'de'
                        && $options['headers']['X-API-Key'] === 'sk_test_key_123'
                        && $options['headers']['Content-Type'] === 'application/json';
                })
            )
            ->willReturn($response);

        $result = $this->synaplanClient->summarize(
            text: 'Test document text',
            summaryType: 'bullet-points',
            length: 'short',
            outputLanguage: 'de',
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Point 1', $result['summary']);
    }

    public function testCreateChatReturnsId(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'id' => 42,
            'title' => 'Test Chat',
        ]));

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with(
                'http://localhost:8000/api/v1/chats',
                $this->callback(function (array $options): bool {
                    $body = json_decode($options['body'], true);

                    return $body['title'] === 'Test Chat';
                })
            )
            ->willReturn($response);

        $result = $this->synaplanClient->createChat('Test Chat');

        $this->assertSame(42, $result['id']);
        $this->assertSame('Test Chat', $result['title']);
    }

    public function testHealthCheckThrowsOnConnectionFailure(): void
    {
        $this->httpClient->method('get')
            ->willThrowException(new \Exception('Connection refused'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Synaplan API request failed'),
                $this->callback(function (array $context): bool {
                    return $context['app'] === Application::APP_ID
                        && $context['path'] === '/api/health';
                })
            );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Connection refused');

        $this->synaplanClient->healthCheck();
    }

    public function testInvalidJsonResponseThrows(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('not valid json');

        $this->httpClient->method('get')->willReturn($response);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON response');

        $this->synaplanClient->healthCheck();
    }

    public function testGetBaseUrlTrimsTrailingSlash(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnMap([
            [Application::APP_ID, 'synaplan_url', 'http://localhost:8000', 'http://example.com/'],
            [Application::APP_ID, 'api_key', '', 'key'],
        ]);

        $client = new SynaplanClient(
            $this->clientService,
            $config,
            $this->logger,
        );

        $this->assertSame('http://example.com', $client->getBaseUrl());
    }

    public function testGetApiKeyReturnsConfiguredKey(): void
    {
        $this->assertSame('sk_test_key_123', $this->synaplanClient->getApiKey());
    }

    public function testGetModelsCallsCorrectEndpoint(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'success' => true,
            'models' => ['CHAT' => [['id' => 1, 'name' => 'gpt-4']]],
        ]));

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with(
                'http://localhost:8000/api/v1/config/models',
                $this->callback(function (array $options): bool {
                    return $options['headers']['X-API-Key'] === 'sk_test_key_123';
                })
            )
            ->willReturn($response);

        $result = $this->synaplanClient->getModels();
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('CHAT', $result['models']);
    }

    public function testGetFileGroupsCallsCorrectEndpoint(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'success' => true,
            'groups' => [['name' => 'DEFAULT', 'count' => 5]],
        ]));

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with(
                'http://localhost:8000/api/v1/files/groups',
                $this->callback(function (array $options): bool {
                    return $options['headers']['X-API-Key'] === 'sk_test_key_123';
                })
            )
            ->willReturn($response);

        $result = $this->synaplanClient->getFileGroups();
        $this->assertTrue($result['success']);
        $this->assertSame('DEFAULT', $result['groups'][0]['name']);
    }

    public function testUploadFileCallsCorrectEndpoint(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'success' => true,
            'files' => [['chunks_created' => 3, 'vectorized' => true]],
        ]));

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with(
                'http://localhost:8000/api/v1/files/upload',
                $this->callback(function (array $options): bool {
                    $multipart = $options['multipart'] ?? [];
                    $filePart = array_values(array_filter($multipart, fn($p) => $p['name'] === 'files[]'))[0] ?? [];
                    $groupPart = array_values(array_filter($multipart, fn($p) => $p['name'] === 'group_key'))[0] ?? [];

                    return $options['headers']['X-API-Key'] === 'sk_test_key_123'
                        && ($filePart['contents'] ?? '') === 'Hello world'
                        && ($filePart['filename'] ?? '') === 'test.txt'
                        && ($groupPart['contents'] ?? '') === 'RESEARCH';
                })
            )
            ->willReturn($response);

        $result = $this->synaplanClient->uploadFile('test.txt', 'Hello world', 'RESEARCH');
        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['files'][0]['chunks_created']);
    }

    public function testAskWithGroupKeyCallsRagSearch(): void
    {
        $ragResponse = $this->createMock(IResponse::class);
        $ragResponse->method('getBody')->willReturn(json_encode([
            'success' => true,
            'results' => [['text' => 'Relevant context chunk']],
        ]));

        $summaryResponse = $this->createMock(IResponse::class);
        $summaryResponse->method('getBody')->willReturn(json_encode([
            'summary' => 'Answer based on RAG context.',
        ]));

        $this->httpClient->expects($this->exactly(2))
            ->method('post')
            ->willReturnOnConsecutiveCalls($ragResponse, $summaryResponse);

        $result = $this->synaplanClient->ask('Question?', '', 'en', 'DEFAULT');
        $this->assertSame('Answer based on RAG context.', $result['summary']);
    }
}
