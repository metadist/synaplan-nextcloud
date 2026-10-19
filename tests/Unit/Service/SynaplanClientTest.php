<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Exception\FailureException;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\UserAccountService;
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
    private UserAccountService&MockObject $userAccounts;
    private SynaplanClient $synaplanClient;

    protected function setUp(): void
    {
        $this->clientService = $this->createMock(IClientService::class);
        $this->httpClient = $this->createMock(IClient::class);
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Per-user resolution off by default: returns null so the client falls
        // back to the install-wide admin key (backward-compatible behaviour).
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->userAccounts->method('getCurrentUserApiKey')->willReturn(null);

        $this->clientService->method('newClient')->willReturn($this->httpClient);
        $this->config->method('getAppValue')->willReturnMap([
            [Application::APP_ID, 'synaplan_url', 'http://localhost:8000', 'http://localhost:8000'],
            [Application::APP_ID, 'api_key', '', 'sk_test_key_123'],
        ]);

        $this->synaplanClient = $this->makeClient($this->config);
    }

    private function makeClient(IConfig $config): SynaplanClient
    {
        return new SynaplanClient(
            $this->clientService,
            $this->logger,
            new SynaplanConfig($config),
            $this->userAccounts,
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

    public function testTranslateUsesTheTranslationModeWhenSynaplanListsIt(): void
    {
        $this->httpClient->expects($this->once())
            ->method('get')
            ->with(
                'http://localhost:8000/api/v1/config/capabilities',
                $this->callback(fn (array $options): bool => !isset($options['headers']['X-API-Key'])),
            )
            ->willReturn($this->capabilities(['abstractive', 'extractive', 'bullet-points', 'translation']));
        $this->httpClient->expects($this->once())
            ->method('post')
            ->with(
                'http://localhost:8000/api/v1/summary/generate',
                $this->callback(fn (array $options): bool => json_decode($options['body'], true) === [
                    'text' => 'Hello world',
                    'summaryType' => 'translation',
                    'outputLanguage' => 'de',
                ]),
            )
            ->willReturn($this->json(['success' => true, 'summary' => 'Hallo Welt']));

        $this->assertSame('Hallo Welt', $this->synaplanClient->translate('Hello world', 'de')['summary']);
    }

    public function testTranslateKeepsTheLongSummaryOnASynaplanWithoutTranslationMode(): void
    {
        $this->httpClient->method('get')
            ->willReturn($this->capabilities(['abstractive', 'extractive', 'bullet-points']));
        $this->httpClient->expects($this->once())
            ->method('post')
            ->with(
                'http://localhost:8000/api/v1/summary/generate',
                $this->callback(fn (array $options): bool => json_decode($options['body'], true) === [
                    'text' => 'Hello world',
                    'summaryType' => 'abstractive',
                    'length' => 'long',
                    'outputLanguage' => 'fr',
                ]),
            )
            ->willReturn($this->json(['success' => true, 'summary' => 'Bonjour le monde']));

        $this->assertSame('Bonjour le monde', $this->synaplanClient->translate('Hello world', 'fr')['summary']);
    }

    public function testTranslateKeepsTheLongSummaryWhenCapabilitiesCannotBeRead(): void
    {
        $this->httpClient->method('get')->willThrowException(new \Exception('Connection refused'));
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('capabilities could not be read'));
        $this->httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(fn (array $options): bool => json_decode($options['body'], true)['summaryType'] === 'abstractive'),
            )
            ->willReturn($this->json(['success' => true, 'summary' => 'Hola mundo']));

        $this->assertSame('Hola mundo', $this->synaplanClient->translate('Hello world', 'es')['summary']);
    }

    public function testCapabilitiesAreReadOncePerRequest(): void
    {
        $this->httpClient->expects($this->once())
            ->method('get')
            ->willReturn($this->capabilities(['translation']));
        $this->httpClient->method('post')->willReturn($this->json(['success' => true, 'summary' => 'Hallo']));

        $this->synaplanClient->translate('Hello', 'de');
        $this->synaplanClient->translate('World', 'de');
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

        $client = $this->makeClient($config);

        $this->assertSame('http://example.com', $client->getBaseUrl());
    }

    public function testGetApiKeyReturnsConfiguredKey(): void
    {
        $this->assertSame('sk_test_key_123', $this->synaplanClient->getApiKey());
    }

    public function testLocalEnvUsesLocalUrlAndKey(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnMap([
            [Application::APP_ID, 'active_env', 'live', 'local'],
            [Application::APP_ID, 'synaplan_url_local', 'http://localhost:8000', 'http://localhost:8000/'],
            [Application::APP_ID, 'api_key_local', '', 'sk_local_abc'],
        ]);

        $client = $this->makeClient($config);

        $this->assertSame('local', $client->getActiveEnv());
        $this->assertSame('http://localhost:8000', $client->getBaseUrl());
        $this->assertSame('sk_local_abc', $client->getApiKey());
    }

    public function testLiveEnvUsesLiveUrlAndKey(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnMap([
            [Application::APP_ID, 'active_env', 'live', 'live'],
            [Application::APP_ID, 'synaplan_url', 'http://localhost:8000', 'https://web.synaplan.com'],
            [Application::APP_ID, 'api_key', '', 'sk_live_xyz'],
        ]);

        $client = $this->makeClient($config);

        $this->assertSame('live', $client->getActiveEnv());
        $this->assertSame('https://web.synaplan.com', $client->getBaseUrl());
        $this->assertSame('sk_live_xyz', $client->getApiKey());
    }

    public function testUnknownActiveEnvFallsBackToLive(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnMap([
            [Application::APP_ID, 'active_env', 'live', 'bogus'],
            [Application::APP_ID, 'synaplan_url', 'http://localhost:8000', 'https://web.synaplan.com'],
            [Application::APP_ID, 'api_key', '', 'sk_live_xyz'],
        ]);

        $client = $this->makeClient($config);

        $this->assertSame('live', $client->getActiveEnv());
        $this->assertSame('https://web.synaplan.com', $client->getBaseUrl());
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
                    $filePart = array_values(array_filter($multipart, fn ($p) => $p['name'] === 'files[]'))[0] ?? [];
                    $groupPart = array_values(array_filter($multipart, fn ($p) => $p['name'] === 'group_key'))[0] ?? [];

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

    public function testLinkMode401ClearsPrefsAndDoesNotRemint(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            function (string $app, string $key, string $default = '') {
                return match ($key) {
                    'mode' => 'link',
                    'per_user_accounts' => '1',
                    'synaplan_url' => 'http://localhost:8000',
                    'api_key' => 'sk_unused',
                    default => $default,
                };
            }
        );
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->userAccounts->method('getCurrentUserApiKey')->willReturn('sk_linked');
        $this->userAccounts->expects($this->once())->method('clearCurrentUserApiKey');
        $this->userAccounts->expects($this->once())->method('clearLinkPrefs');

        $this->httpClient->expects($this->once())
            ->method('get')
            ->willThrowException(new \Exception('401 Unauthorized'));

        $client = $this->makeClient($config);

        try {
            $client->healthCheck();
            self::fail('A refused linked key must fail.');
        } catch (FailureException $e) {
            self::assertSame('not_connected', $e->failure->code);
        }
    }

    public function testWithoutALinkedKeyNothingIsSent(): void
    {
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->userAccounts->method('getCurrentUserApiKey')->willReturn(null);
        $this->httpClient->expects($this->never())->method('post');

        try {
            $this->makeClient($this->modeConfig('link'))->summarize('Notes');
            self::fail('Summarize without a key must fail.');
        } catch (FailureException $e) {
            self::assertSame([409, 'not_connected'], [$e->failure->status, $e->failure->code]);
        }
    }

    public function testWithoutAKeyAProvisionedPersonIsAskedToActivateFirst(): void
    {
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->userAccounts->method('getCurrentUserApiKey')->willReturn(null);
        $this->userAccounts->method('hasConsentForCurrentUser')->willReturn(false);

        $this->expectExceptionObject(FailureException::consentRequired());
        $this->makeClient($this->modeConfig('provision'))->requireApiKey();
    }

    public function testAnActivatedPersonWithoutAKeyIsToldTheAccountIsUnavailable(): void
    {
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->userAccounts->method('getCurrentUserApiKey')->willReturn(null);
        $this->userAccounts->method('hasConsentForCurrentUser')->willReturn(true);

        try {
            $this->makeClient($this->modeConfig('provision'))->requireApiKey();
            self::fail('A missing key after consent must fail.');
        } catch (FailureException $e) {
            self::assertSame([502, 'account_unavailable'], [$e->failure->status, $e->failure->code]);
        }
    }

    public function testTheHealthCheckNeedsNoKey(): void
    {
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->userAccounts->method('getCurrentUserApiKey')->willReturn(null);
        $this->httpClient->expects($this->once())->method('get')->willReturn($this->json(['status' => 'ok']));

        $this->assertSame('ok', $this->makeClient($this->modeConfig('link'))->healthCheck()['status']);
    }

    public function testAKeyRefusedInAStreamAsksALinkedPersonToConnectAgain(): void
    {
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->userAccounts->expects($this->once())->method('clearCurrentUserApiKey');
        $this->userAccounts->expects($this->once())->method('clearLinkPrefs');

        $this->assertSame('not_connected', $this->makeClient($this->modeConfig('link'))->keyRefused()->code);
    }

    public function testAKeyRefusedInAStreamIsTheAdministratorsMatterInSharedMode(): void
    {
        $this->userAccounts->expects($this->never())->method('clearCurrentUserApiKey');

        $this->assertSame('synaplan_key_rejected', $this->synaplanClient->keyRefused()->code);
    }

    private function modeConfig(string $mode): IConfig
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => match ($key) {
                'mode' => $mode,
                'synaplan_url' => 'http://localhost:8000',
                'api_key' => 'sk_admin',
                default => $default,
            },
        );

        return $config;
    }

    public function testProvisionMode401StillRetriesOnce(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            function (string $app, string $key, string $default = '') {
                return match ($key) {
                    'mode' => 'provision',
                    'per_user_accounts' => '1',
                    'synaplan_url' => 'http://localhost:8000',
                    'api_key' => 'sk_unused',
                    default => $default,
                };
            }
        );
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->userAccounts->method('getCurrentUserApiKey')->willReturnOnConsecutiveCalls('sk_stale', 'sk_fresh');
        $this->userAccounts->expects($this->once())->method('clearCurrentUserApiKey');
        $this->userAccounts->expects($this->never())->method('clearLinkPrefs');

        $ok = $this->createMock(IResponse::class);
        $ok->method('getBody')->willReturn(json_encode(['status' => 'ok']));
        $calls = 0;
        $this->httpClient->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function () use (&$calls, $ok): IResponse {
                ++$calls;
                if ($calls === 1) {
                    throw new \Exception('401 Unauthorized');
                }

                return $ok;
            });

        $client = $this->makeClient($config);
        $result = $client->healthCheck();

        $this->assertSame('ok', $result['status']);
    }

    public function testChatPostsTheMessagesAndTheChosenModel(): void
    {
        $messages = [['role' => 'user', 'content' => 'By how much did restores exceed the contract?']];
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'By four hours.']]],
        ]));

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->stringEndsWith('/v1/chat/completions'),
                $this->callback(fn (array $options): bool => json_decode($options['body'], true)
                    === ['messages' => $messages, 'stream' => false, 'model' => 'gpt-oss-120b']),
            )
            ->willReturn($response);

        $this->assertSame('By four hours.', $this->synaplanClient->chat($messages, 'gpt-oss-120b'));
    }

    /**
     * @param list<string> $types
     */
    private function capabilities(array $types): IResponse
    {
        return $this->json(['summary' => ['types' => $types, 'lengths' => ['short', 'medium', 'long'], 'focus_areas' => []]]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body): IResponse
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn((string) json_encode($body));

        return $response;
    }
}
