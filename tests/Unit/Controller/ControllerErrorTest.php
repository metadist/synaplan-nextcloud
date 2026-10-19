<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use OCA\SynaplanIntegration\Controller\ApiController;
use OCA\SynaplanIntegration\Controller\ChatController;
use OCA\SynaplanIntegration\Controller\MediaController;
use OCA\SynaplanIntegration\Exception\FailureException;
use OCA\SynaplanIntegration\Service\LanguageService;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Every controller action that calls Synaplan answers a failure with a status
 * and a code, never with the exception text (it can carry internal addresses).
 */
final class ControllerErrorTest extends TestCase
{
    private const CURL_ERROR = 'cURL error 7: Failed to connect to backend port 80 after 0 ms: Could not connect to server';

    private SynaplanClient&MockObject $client;
    private ApiController $api;
    private ChatController $chat;
    private MediaController $media;

    protected function setUp(): void
    {
        $this->client = $this->createMock(SynaplanClient::class);

        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('ada');
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $file = $this->createStub(File::class);
        $file->method('getContent')->willReturn('Meeting notes');
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getSize')->willReturn(13);
        $file->method('getName')->willReturn('notes.txt');
        $file->method('getEtag')->willReturn('etag-1');
        $file->method('getPath')->willReturn('/ada/files/notes.txt');
        $folder = $this->createStub(Folder::class);
        $folder->method('getFirstNodeById')->willReturn($file);
        $folder->method('getRelativePath')->willReturn('/notes.txt');
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($folder);

        $language = $this->createStub(LanguageService::class);
        $language->method('resolveLanguage')->willReturn('en');
        $language->method('getLanguageName')->willReturn('English');

        $request = $this->createStub(IRequest::class);
        $logger = new NullLogger();
        $this->api = new ApiController(
            $request,
            $this->client,
            $root,
            $session,
            $language,
            $this->createStub(IConfig::class),
            $this->createStub(IAppManager::class),
            $logger,
        );
        $this->chat = new ChatController($request, $this->client, $root, $session, $language, $logger);
        $this->media = new MediaController($request, $this->client, $root, $session, $logger);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actions(): iterable
    {
        foreach (['summarize', 'translate', 'uploadToKnowledge', 'removeFromKnowledge', 'getGroups', 'getModels', 'chat', 'mediaProxy'] as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('actions')]
    public function testWithoutAKeyTheActionAsksToConnect(string $action): void
    {
        $response = $this->failWith($action, FailureException::notConnected());

        self::assertSame(409, $response->getStatus());
        self::assertSame(['success' => false, 'error' => 'not_connected'], $this->withoutExtras($response));
    }

    #[DataProvider('actions')]
    public function testUnreachableSynaplanIsABadGatewayWithoutTheExceptionText(string $action): void
    {
        $response = $this->failWith($action, new ConnectException(self::CURL_ERROR));

        self::assertSame(502, $response->getStatus());
        self::assertSame(['success' => false, 'error' => 'synaplan_unreachable'], $this->withoutExtras($response));
        self::assertStringNotContainsString('backend', (string) json_encode($response->getData()));
    }

    public function testTheStreamRefusesBeforeStreamingWithoutAKey(): void
    {
        $this->client->method('requireApiKey')->willThrowException(FailureException::notConnected());

        $response = $this->chat->chatStream('Hello');

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(409, $response->getStatus());
        self::assertSame('not_connected', $response->getData()['error']);
    }

    private function failWith(string $action, \Throwable $failure): JSONResponse
    {
        $method = match ($action) {
            'uploadToKnowledge' => 'uploadFile',
            'removeFromKnowledge' => 'knowledgeStatus',
            'getGroups' => 'getFileGroups',
            'mediaProxy' => 'downloadFile',
            default => $action,
        };
        $this->client->method($method)->willThrowException($failure);

        $response = match ($action) {
            'summarize' => $this->api->summarize(42),
            'translate' => $this->api->translate(42, 'de'),
            'uploadToKnowledge' => $this->api->uploadToKnowledge(42, 'DOCS'),
            'removeFromKnowledge' => $this->api->removeFromKnowledge(42),
            'getGroups' => $this->api->getGroups(),
            'getModels' => $this->api->getModels(),
            'chat' => $this->chat->chat('Hello'),
            'mediaProxy' => $this->media->proxy('/api/v1/files/uploads/7/cat.png'),
            default => throw new \LogicException('No call for action ' . $action),
        };
        self::assertInstanceOf(JSONResponse::class, $response);

        return $response;
    }

    /**
     * The failure keys only: groups and models answer with empty lists beside them.
     *
     * @return array<string, mixed>
     */
    private function withoutExtras(JSONResponse $response): array
    {
        return array_intersect_key($response->getData(), array_flip(['success', 'error', 'message']));
    }
}

/** Named like Guzzle's connection failure, which the Nextcloud HTTP client lets through. */
final class ConnectException extends \RuntimeException
{
}
