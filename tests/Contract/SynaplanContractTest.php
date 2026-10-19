<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Contract;

use OCA\SynaplanIntegration\Controller\ChatController;
use OCA\SynaplanIntegration\Http\SseProxyResponse;
use OCA\SynaplanIntegration\Service\LanguageService;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Every call the app makes to Synaplan, checked against the Synaplan pinned in
 * .github/workflows/ci.yml: the route and method exist, required fields are
 * sent, no undeclared field is sent and enum values are accepted. A call the
 * pinned Synaplan lacks fails here instead of for a user.
 *
 * Spec and routes come from scripts/synaplan-openapi.sh (make test-contract).
 */
final class SynaplanContractTest extends TestCase
{
    private const BASE_URL = 'http://synaplan.test';

    private const CACHE = __DIR__ . '/.cache';

    /** A body every caller can read far enough to make its next call. */
    private const RESPONSE = [
        'success' => true,
        'status' => 'ok',
        'user' => ['id' => 42],
        'api_key' => ['id' => 7, 'key' => 'sk_contract'],
        'instance_id' => 'instance-1',
        'instance_secret' => 'secret-1',
        'results' => [],
        'files' => [['id' => 42]],
        'file' => ['url' => '/api/v1/files/uploads/7/cat.png', 'type' => 'image', 'mimeType' => 'image/png'],
    ];

    /** @var list<array{method: string, path: string, body: array<string, mixed>, multipart: bool}> */
    private array $calls = [];

    /** @var array<string, string> */
    private array $appConfig = [
        'synaplan_url' => self::BASE_URL,
        'api_key' => 'sk_admin',
        'per_user_accounts' => '1',
    ];

    /** @var array<string, string> */
    private array $userConfig = [];

    public function testEveryCallIsAcceptedByThePinnedSynaplan(): void
    {
        $this->callEverything();
        $contract = new OpenApiContract(self::CACHE . '/openapi.json', self::CACHE . '/routes.json');

        $violations = [];
        foreach ($this->calls as $call) {
            array_push($violations, ...$contract->violations($call['method'], $call['path'], $call['body'], $call['multipart']));
        }

        self::assertSame([], array_values(array_unique($violations)));
    }

    public function testEveryPathTheAppNamesIsCalledAbove(): void
    {
        $this->callEverything();
        $called = array_column($this->calls, 'path');

        $missing = [];
        foreach (self::pathLiteralsInLib() as $literal) {
            $hit = array_filter($called, static fn (string $path): bool => str_starts_with($path, $literal));
            if ($hit === []) {
                $missing[] = $literal;
            }
        }

        self::assertSame([], $missing, 'Add a call to callEverything() for each new Synaplan path.');
    }

    private function callEverything(): void
    {
        $this->calls = [];
        $config = $this->config();
        $synaplanConfig = new SynaplanConfig($config);
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($this->ada());
        $accounts = new UserAccountService($this->recordingClients(), $config, $session, $synaplanConfig, new NullLogger());
        $client = new SynaplanClient($this->recordingClients(), new NullLogger(), $synaplanConfig, $accounts);
        $links = new PlatformLinkService(
            $this->recordingClients(),
            $config,
            $this->urlGenerator(),
            $this->crypto(),
            $synaplanConfig,
            $accounts,
            new NullLogger(),
        );

        $scenarios = [
            fn () => $client->healthCheck(),
            fn () => $client->summarize('A long text.'),
            fn () => $client->createChat('Contract'),
            fn () => $client->ask('What changed?', 'Context', 'en', 'Contracts'),
            fn () => $client->checkMemoryService(),
            fn () => $client->searchMemoryContext('budget'),
            fn () => $client->getModels(),
            fn () => $client->getFileGroups(),
            fn () => $client->getFileContent(42),
            fn () => $client->knowledgeStatus(7, 'etag-1'),
            fn () => $client->deleteKnowledgeFile(42),
            fn () => $client->uploadFile('report.pdf', '%PDF', 'Contracts', 'vectorize', '/Projects/report.pdf', '7', 'etag-1', true),
            fn () => $client->generateMedia('A cat', 'image', 3),
            fn () => $client->downloadFile('/api/v1/files/uploads/7/cat.png'),
            fn () => $accounts->provisionAccount($this->ada()),
            fn () => $accounts->fetchUsage(42),
            fn () => $accounts->deleteRemoteAccount('ada'),
            fn () => $links->registerInstance('sk_admin'),
            fn () => $links->instanceStatus(),
            fn () => $links->exchange('code-1'),
            fn () => $links->disconnectUid('ada'),
            fn () => $this->recordChatStream($client, $session),
        ];
        foreach ($scenarios as $scenario) {
            try {
                $scenario();
            } catch (\Throwable) {
                // Only the outgoing request matters here; testEveryPathTheAppNamesIsCalledAbove
                // catches a scenario that fails before it calls out.
            }
        }
    }

    private function recordChatStream(SynaplanClient $client, IUserSession $session): void
    {
        $language = $this->createStub(LanguageService::class);
        $language->method('resolveLanguage')->willReturn('en');
        $language->method('getLanguageName')->willReturn('English');
        $controller = new ChatController(
            $this->createStub(IRequest::class),
            $client,
            $this->createStub(IRootFolder::class),
            $session,
            $language,
            new NullLogger(),
        );

        $response = $controller->chatStream('Hello', null, null, 'gpt-model', false);
        self::assertInstanceOf(SseProxyResponse::class, $response);
        $read = fn (string $property): string => (string) (new \ReflectionProperty(SseProxyResponse::class, $property))->getValue($response);
        $this->record('POST', $read('url'), ['body' => $read('payload')]);
    }

    private function recordingClients(): IClientService
    {
        $client = $this->createStub(IClient::class);
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $verb) {
            $client->method($verb)->willReturnCallback(
                fn (string $url, array $options = []): IResponse => $this->record(strtoupper($verb), $url, $options),
            );
        }
        $client->method('request')->willReturnCallback(
            fn (string $method, string $url, array $options = []): IResponse => $this->record(strtoupper($method), $url, $options),
        );
        $service = $this->createStub(IClientService::class);
        $service->method('newClient')->willReturn($client);

        return $service;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function record(string $method, string $url, array $options): IResponse
    {
        $path = (string) parse_url(substr($url, strlen(self::BASE_URL)), PHP_URL_PATH);
        $multipart = isset($options['multipart']);
        $body = [];
        if ($multipart) {
            foreach ($options['multipart'] as $part) {
                $body[$part['name']] = is_string($part['contents']) ? $part['contents'] : '';
            }
        } elseif (isset($options['json']) && is_array($options['json'])) {
            $body = $options['json'];
        } elseif (isset($options['body']) && is_string($options['body'])) {
            $body = json_decode($options['body'], true) ?? [];
        }
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body, 'multipart' => $multipart];

        $response = $this->createStub(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn((string) json_encode(self::RESPONSE));

        return $response;
    }

    private function config(): IConfig
    {
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $this->appConfig[$key] ?? $default,
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value): void {
                $this->appConfig[$key] = $value;
            },
        );
        $config->method('getUserValue')->willReturnCallback(
            fn (string $uid, string $app, string $key, string $default = ''): string => $this->userConfig[$uid . '|' . $key] ?? $default,
        );
        $config->method('setUserValue')->willReturnCallback(
            function (string $uid, string $app, string $key, string $value): void {
                $this->userConfig[$uid . '|' . $key] = $value;
            },
        );
        $config->method('getSystemValueString')->willReturn('instance42');

        return $config;
    }

    private function ada(): IUser
    {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('ada');
        $user->method('getEMailAddress')->willReturn('ada@example.org');
        $user->method('getDisplayName')->willReturn('Ada Example');

        return $user;
    }

    private function urlGenerator(): IURLGenerator
    {
        $urls = $this->createStub(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturn('http://nextcloud.test/apps/synaplan_integration/link/callback');
        $urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'http://nextcloud.test' . $path);

        return $urls;
    }

    private function crypto(): ICrypto
    {
        $crypto = $this->createStub(ICrypto::class);
        $crypto->method('encrypt')->willReturnCallback(fn (string $plain): string => 'enc:' . $plain);
        $crypto->method('decrypt')->willReturnCallback(fn (string $cipher): string => substr($cipher, 4));

        return $crypto;
    }

    /**
     * @return list<string>
     */
    private static function pathLiteralsInLib(): array
    {
        $literals = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/lib'));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                preg_match_all("#'(/(?:api|v1)/[^'\$]*)'#", (string) file_get_contents($file->getPathname()), $matches);
                array_push($literals, ...$matches[1]);
            }
        }

        return array_values(array_unique($literals));
    }
}
