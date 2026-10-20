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
use OCA\SynaplanIntegration\Tests\Unit\Support\KeyStorageStubs;
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
    use KeyStorageStubs;

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

    /** Lets translate() take the translation mode, so its request is checked too. */
    private const CAPABILITIES = [
        'summary' => ['types' => ['abstractive', 'extractive', 'bullet-points', 'translation']],
    ];

    /** @var list<array{method: string, path: string, body: array<string, mixed>, multipart: bool}> */
    private array $calls = [];

    /** @var array<string, string> */
    private array $appConfig = [
        'synaplan_url' => self::BASE_URL,
        'api_key' => 'sk_admin',
        'per_user_accounts' => '1',
    ];

    /** Ada activated AI, so her calls go out with her own key. */
    /** @var array<string, string> */
    private array $userConfig = ['ada|synaplan_user_api_key' => 'sk_ada'];

    public function testEveryCallIsAcceptedByThePinnedSynaplan(): void
    {
        $this->callEverything();
        $contract = $this->contract();

        $violations = [];
        foreach ($this->calls as $call) {
            array_push($violations, ...$contract->violations($call['method'], $call['path'], $call['body'], $call['multipart']));
        }

        self::assertSame([], array_values(array_unique($violations)));
    }

    public function testEveryOperationTheAppNamesIsCalled(): void
    {
        $this->callEverything();

        $missing = [];
        foreach (self::operationsInLib() as $operation) {
            $hit = false;
            foreach ($this->calls as $call) {
                [$method, $template] = explode(' ', $operation, 2);
                if (OpenApiContract::operationCalled($method, $template, $call['method'], $call['path'])) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                $missing[] = $operation;
            }
        }

        self::assertSame([], $missing, 'Add a call to callEverything() for each Synaplan operation.');
    }

    public function testASelectedModelIsSentToTheChatEndpoint(): void
    {
        $this->callEverything();
        $selected = array_filter(
            $this->calls,
            static fn (array $call): bool => $call['path'] === '/v1/chat/completions'
                && ($call['body']['model'] ?? null) === 'gpt-oss-120b',
        );

        self::assertNotEmpty($selected, 'callEverything() must exercise a selected model.');
    }

    public function testTheTranslationRequestIsChecked(): void
    {
        $this->callEverything();
        $translations = array_filter(
            $this->calls,
            static fn (array $call): bool => $call['path'] === '/api/v1/summary/generate'
                && ($call['body']['summaryType'] ?? null) === 'translation',
        );

        self::assertNotEmpty($translations, 'callEverything() must exercise the translation mode.');
    }

    public function testNestedItemFieldsAreChecked(): void
    {
        $contract = $this->contract();
        $renamed = $contract->violations('POST', '/api/v1/files/check-stale', [
            'items' => [['sourceId' => '7', 'source_etag' => null]],
        ], false);
        self::assertNotSame([], $renamed);
        self::assertStringContainsString("sends 'items[0].sourceId'", implode("\n", $renamed));

        self::assertSame([], $contract->violations('POST', '/api/v1/files/check-stale', [
            'source' => 'nextcloud',
            'items' => [['source_id' => '7', 'source_etag' => 'etag-1']],
        ], false));
    }

    public function testAFinalParameterDoesNotMatchExtraSegments(): void
    {
        $contract = $this->contract();
        $extra = $contract->violations('DELETE', '/api/v1/files/42/not-a-route', [], false);
        self::assertNotSame([], $extra);
        self::assertStringContainsString('no such route', implode("\n", $extra));

        self::assertSame([], $contract->violations('DELETE', '/api/v1/files/42', [], false));
        self::assertSame([], $contract->violations('GET', '/api/v1/files/uploads/7/cat.png', [], false));
    }

    public function testASharedPrefixDoesNotCoverAnotherOperation(): void
    {
        self::assertFalse(OpenApiContract::operationCalled(
            'DELETE',
            '/api/v1/files/{id}',
            'GET',
            '/api/v1/files/42/content',
        ));
        self::assertFalse(OpenApiContract::operationCalled(
            'GET',
            '/api/v1/admin/users/{id}/usage',
            'DELETE',
            '/api/v1/admin/users/42',
        ));
        self::assertTrue(OpenApiContract::operationCalled(
            'DELETE',
            '/api/v1/files/{id}',
            'DELETE',
            '/api/v1/files/42',
        ));
        self::assertTrue(OpenApiContract::operationCalled(
            'GET',
            '/api/v1/admin/users/{id}/usage',
            'GET',
            '/api/v1/admin/users/42/usage',
        ));
    }

    private function contract(): OpenApiContract
    {
        return new OpenApiContract(self::CACHE . '/openapi.json', self::CACHE . '/routes.json');
    }

    private function callEverything(): void
    {
        $this->calls = [];
        $config = $this->config();
        $keys = $this->keyStorageFrom($config);
        $synaplanConfig = new SynaplanConfig($config, $keys);
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($this->ada());
        $accounts = new UserAccountService($this->recordingClients(), $config, $session, $synaplanConfig, new NullLogger(), $keys);
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
            fn () => $client->translate('A long text.', 'de'),
            fn () => $client->createChat('Contract'),
            fn () => $client->searchRagContext('What changed?', 'Contracts'),
            fn () => $client->chat([['role' => 'user', 'content' => 'What changed?']], 'gpt-oss-120b'),
            fn () => $this->recordBlockingChat($client, $session),
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
            } catch (\Throwable $error) {
                if ($error instanceof \PHPUnit\Framework\AssertionFailedError) {
                    throw $error;
                }
                // The response only has to be readable. testEveryOperationTheAppNamesIsCalled
                // fails when a scenario throws before the request goes out.
            }
        }
    }

    private function recordBlockingChat(SynaplanClient $client, IUserSession $session): void
    {
        $response = $this->chatController($client, $session)->chat('Which model?', null, null, 'gpt-oss-120b');
        self::assertTrue($response->getData()['success'] ?? false);
    }

    private function recordChatStream(SynaplanClient $client, IUserSession $session): void
    {
        $response = $this->chatController($client, $session)->chatStream('Hello', null, null, 'gpt-model', false);
        self::assertInstanceOf(SseProxyResponse::class, $response);
        $read = fn (string $property): string => (string) (new \ReflectionProperty(SseProxyResponse::class, $property))->getValue($response);
        $this->record('POST', $read('url'), ['body' => $read('payload')]);
    }

    private function chatController(SynaplanClient $client, IUserSession $session): ChatController
    {
        $language = $this->createStub(LanguageService::class);
        $language->method('resolveLanguage')->willReturn('en');
        $language->method('getLanguageName')->willReturn('English');

        return new ChatController(
            $this->createStub(IRequest::class),
            $client,
            $this->createStub(IRootFolder::class),
            $session,
            $language,
            new NullLogger(),
        );
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
        $response->method('getBody')->willReturn((string) json_encode(
            $path === '/api/v1/config/capabilities' ? self::CAPABILITIES : self::RESPONSE,
        ));

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
     * Method plus full path template for every Synaplan call under lib/.
     * A quoted path that this cannot parse is reported as UNPARSED.
     *
     * @return list<string>
     */
    private static function operationsInLib(): array
    {
        $operations = [];
        $quoted = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/lib'));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all("#'(/(?:api|v1)/[^'\$]*)'#", $source, $literals);
            array_push($quoted, ...$literals[1]);
            preg_match_all(
                '/->(?:request|adminRequest)\(\s*\'(GET|POST|PUT|PATCH|DELETE|HEAD)\'\s*,\s*/',
                $source,
                $calls,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );
            foreach ($calls as $call) {
                $expression = self::readArgument($source, $call[0][1] + strlen($call[0][0]));
                $template = self::templateFromExpression($expression);
                $operations[] = $template === null
                    ? 'UNPARSED ' . $call[1][0] . ' ' . $expression
                    : $call[1][0] . ' ' . $template;
            }
            preg_match_all(
                '/getBaseUrl\(\)\s*\.\s*\'(\/(?:api|v1)\/[^\']*)\'/',
                $source,
                $urls,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );
            foreach ($urls as $url) {
                $verb = self::verbBeside($source, $url[0][1]);
                $operations[] = $verb === null ? 'UNPARSED ' . $url[1][0] : $verb . ' ' . $url[1][0];
            }
        }

        $operations = array_values(array_unique($operations));
        foreach (array_unique($quoted) as $literal) {
            $parsed = false;
            foreach ($operations as $operation) {
                $template = explode(' ', $operation, 2)[1] ?? '';
                if (str_starts_with($template, $literal)) {
                    $parsed = true;
                    break;
                }
            }
            if (!$parsed) {
                $operations[] = 'UNPARSED ' . $literal;
            }
        }
        sort($operations);

        return $operations;
    }

    private static function readArgument(string $source, int $offset): string
    {
        $length = strlen($source);
        $depth = 0;
        $quote = null;
        $argument = '';
        for ($i = $offset; $i < $length; $i++) {
            $character = $source[$i];
            if ($quote !== null) {
                $argument .= $character;
                if ($character === '\\' && $i + 1 < $length) {
                    $argument .= $source[++$i];
                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === '\'' || $character === '"') {
                $quote = $character;
                $argument .= $character;
                continue;
            }
            if ($character === '(') {
                $depth++;
                $argument .= $character;
                continue;
            }
            if ($character === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
                $argument .= $character;
                continue;
            }
            if ($character === ',' && $depth === 0) {
                break;
            }
            $argument .= $character;
        }

        return trim($argument);
    }

    private static function templateFromExpression(string $expression): ?string
    {
        $template = '';
        $length = strlen($expression);
        for ($i = 0; $i < $length; $i++) {
            $character = $expression[$i];
            if ($character === '\'' || $character === '"') {
                $quote = $character;
                for ($i++; $i < $length && $expression[$i] !== $quote; $i++) {
                    if ($expression[$i] === '\\' && $i + 1 < $length) {
                        $i++;
                    }
                    $template .= $expression[$i];
                }
                continue;
            }
            if (preg_match('/^rawurlencode\s*\(\s*\$(\w+)\s*\)/', substr($expression, $i), $match) === 1) {
                $template .= '{' . $match[1] . '}';
                $i += strlen($match[0]) - 1;
                continue;
            }
            if ($character === '$' && preg_match('/^(\w+)/', substr($expression, $i + 1), $match) === 1) {
                $template .= '{' . $match[1] . '}';
                $i += strlen($match[1]);
            }
        }

        return str_starts_with($template, '/') ? $template : null;
    }

    private static function verbBeside(string $source, int $offset): ?string
    {
        $start = strrpos(substr($source, 0, $offset), 'function ');
        $start = $start === false ? 0 : $start;
        $end = strlen($source);
        if (preg_match('/\n    (?:public|private|protected) function /', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $end = $match[0][1];
        }
        $body = substr($source, $start, $end - $start);
        if (str_contains($body, 'new SseProxyResponse')) {
            return 'POST';
        }
        if (preg_match('/->(get|post|put|patch|delete)\(/', $body, $match) === 1) {
            return strtoupper($match[1]);
        }

        return null;
    }
}
