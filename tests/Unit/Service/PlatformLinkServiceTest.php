<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Exception\PlatformLinkException;
use OCA\SynaplanIntegration\Service\OAuthClient;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCA\SynaplanIntegration\Tests\Unit\Support\ArrayCache;
use OCA\SynaplanIntegration\Tests\Unit\Support\ArrayCacheFactory;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PlatformLinkServiceTest extends TestCase
{
    private IClientService&MockObject $clientService;
    private IClient&MockObject $httpClient;
    private IConfig&MockObject $config;
    private IURLGenerator&MockObject $urlGenerator;
    private OAuthClient&MockObject $oauth;
    private IAppManager&MockObject $appManager;
    private SynaplanConfig&MockObject $synaplanConfig;
    private UserAccountService&MockObject $userAccounts;
    private LoggerInterface&MockObject $logger;
    private ArrayCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
        $this->clientService = $this->createMock(IClientService::class);
        $this->httpClient = $this->createMock(IClient::class);
        $this->config = $this->createMock(IConfig::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->oauth = $this->createMock(OAuthClient::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->synaplanConfig = $this->createMock(SynaplanConfig::class);
        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->clientService->method('newClient')->willReturn($this->httpClient);
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturn(
            'https://files.example.org/apps/synaplan_integration/link/callback'
        );
        $this->synaplanConfig->method('getBaseUrl')->willReturn('https://synaplan.example');
    }

    private function service(): PlatformLinkService
    {
        return new PlatformLinkService(
            $this->clientService,
            $this->config,
            $this->urlGenerator,
            $this->oauth,
            $this->appManager,
            $this->synaplanConfig,
            $this->userAccounts,
            $this->logger,
            new ArrayCacheFactory($this->cache),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function configurations(): iterable
    {
        yield 'folder reads and the read setting' => [['capabilities' => ['deliveries', 'pickups', 'read_scope']], true];
        yield 'no read setting' => [['capabilities' => ['deliveries', 'pickups']], false];
        yield 'no folder reads' => [['capabilities' => ['deliveries', 'read_scope']], false];
        yield 'a configuration without capabilities' => [[], false];
    }

    /**
     * @param array<string, mixed> $offers
     */
    #[DataProvider('configurations')]
    public function testSynaplanReadsFoldersOnlyWhenItOffersFolderReadsAndTheReadSetting(array $offers, bool $reads): void
    {
        $this->httpClient->expects($this->once())->method('get')
            ->with('https://synaplan.example/.well-known/synaplan-configuration', $this->callback(
                static fn (array $options): bool => $options['timeout'] === 10,
            ))
            ->willReturn($this->answer(200, (string) json_encode(['issuer' => 'https://synaplan.example'] + $offers)));

        self::assertSame([$reads, $reads], [$this->service()->readsFolders(), $this->service()->readsFolders()]);
        self::assertSame([300], array_values($this->cache->ttls));
    }

    public function testASynaplanWithPlatformLinksOffReadsNoFolders(): void
    {
        $this->httpClient->expects($this->once())->method('get')->willReturn($this->answer(404, ''));

        self::assertFalse($this->service()->readsFolders());
        self::assertFalse($this->service()->readsFolders());
        self::assertSame([300], array_values($this->cache->ttls));
    }

    public function testASynaplanThatDoesNotAnswerIsAskedAgainAfterAMinute(): void
    {
        $this->httpClient->expects($this->once())->method('get')->willThrowException(new \RuntimeException('cURL error 28'));

        self::assertFalse($this->service()->readsFolders());
        self::assertFalse($this->service()->readsFolders());
        self::assertSame([60], array_values($this->cache->ttls));
    }

    public function testRegisterSendsThisServerAndWhatTheAppDoes(): void
    {
        $this->synaplanConfig->method('getInstanceId')->willReturn('oc0abc');
        $this->config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $key === 'delivery_url' ? 'https://files.example.org/inbound' : $default
        );
        $this->urlGenerator->method('getAbsoluteURL')->with('/')->willReturn('https://files.example.org/');
        $this->appManager->method('getAppVersion')->willReturn('2.0.0');
        $this->oauth->method('clientId')->willReturn('client-1');
        $this->oauth->expects($this->once())->method('register')
            ->with([
                'client_uri' => 'https://files.example.org/',
                'redirect_uri' => 'https://files.example.org/apps/synaplan_integration/link/callback',
                'delivery_url' => 'https://files.example.org/inbound',
                'capabilities' => ['deliveries', 'pickups', 'read_scope', 'source_events', 'watches'],
                'server_id' => 'oc0abc',
                'version' => '2.0.0',
            ], 'reg-token')
            ->willReturn(['client_id' => 'client-1', 'status' => 'active']);

        $this->assertSame(
            ['instance_id' => 'client-1', 'status' => 'active'],
            $this->service()->register('reg-token'),
        );
    }

    public function testTheDefaultAddressIsThisServersOwnRouteAlsoOnLocalhost(): void
    {
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->urlGenerator->method('linkToRouteAbsolute')
            ->with('synaplan_integration.inbound.receive')
            ->willReturn('http://localhost:8081/index.php/apps/synaplan_integration/api/v1/inbound');

        $this->assertSame(
            'http://localhost:8081/index.php/apps/synaplan_integration/api/v1/inbound',
            $this->service()->defaultDeliveryUrl(),
        );
    }

    public function testRegistrationStatusReadsTheClientConfiguration(): void
    {
        $this->oauth->method('isRegistered')->willReturn(true);
        $this->oauth->method('configuration')->willReturn([
            'status' => 'active',
            'client_uri' => 'https://files.example.org/',
            'delivery_url' => 'https://files.example.org/inbound',
            'reachable' => true,
        ]);

        $status = $this->service()->registrationStatus();

        $this->assertSame([
            'status' => 'active',
            'host' => 'https://files.example.org',
            'delivery_url' => 'https://files.example.org/inbound',
            'reachable' => true,
        ], $status);
    }

    public function testARegistrationSynaplanNoLongerAcceptsIsRefused(): void
    {
        $this->oauth->method('isRegistered')->willReturn(true);
        $this->oauth->method('configuration')->willThrowException(
            new PlatformLinkException('A client assertion of this client is required.', PlatformLinkException::CODE_CLIENT_REFUSED),
        );
        $this->config->expects($this->once())->method('setAppValue')
            ->with(Application::APP_ID, 'link_registration_status', 'refused');

        $this->assertSame([
            'status' => 'refused',
            'host' => '',
            'delivery_url' => '',
            'reachable' => null,
        ], $this->service()->registrationStatus());
    }

    public function testAnUnansweredStatusQuestionIsNotARefusal(): void
    {
        $this->oauth->method('isRegistered')->willReturn(true);
        $this->oauth->method('configuration')->willThrowException(
            new PlatformLinkException('Synaplan cannot be reached.', PlatformLinkException::CODE_UNREACHABLE),
        );
        $this->config->expects($this->never())->method('setAppValue');

        $this->expectException(PlatformLinkException::class);

        $this->service()->registrationStatus();
    }

    public function testARefusedRegistrationNoLongerWaitsForApproval(): void
    {
        $this->config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = ''): string => $key === 'link_registration_status' ? 'pending' : $default
        );
        $this->oauth->method('isRegistered')->willReturn(true);
        $this->oauth->method('configuration')->willThrowException(
            new PlatformLinkException('A client assertion of this client is required.', PlatformLinkException::CODE_CLIENT_REFUSED),
        );

        $this->assertFalse($this->service()->isWaitingForApproval());
    }

    public function testRegistrationStatusIsNullWhenNotRegistered(): void
    {
        $this->oauth->method('isRegistered')->willReturn(false);
        $this->oauth->expects($this->never())->method('configuration');

        $this->assertNull($this->service()->registrationStatus());
    }

    public function testForgetRegistrationLeavesSynaplan(): void
    {
        $this->oauth->expects($this->once())->method('unregister');

        $this->service()->forgetRegistration();
    }

    public function testWhoAmIAsksWithThePersonsAccessToken(): void
    {
        $this->userAccounts->method('credentialFor')->with('jdoe')->willReturn('at_jdoe');
        $resp = $this->createMock(IResponse::class);
        $resp->method('getStatusCode')->willReturn(200);
        $resp->method('getBody')->willReturn('{"user":{"id":9,"email":"j@doe.test"}}');
        $this->httpClient->expects($this->once())->method('get')
            ->with(
                'https://synaplan.example/api/v1/auth/me',
                $this->callback(fn (array $o): bool => ($o['headers']['Authorization'] ?? '') === 'Bearer at_jdoe'
                    && !isset($o['headers']['X-API-Key']))
            )
            ->willReturn($resp);

        $this->assertSame(['id' => 9, 'email' => 'j@doe.test'], $this->service()->whoAmI('jdoe'));
    }

    public function testWhoAmINeedsACredential(): void
    {
        $this->userAccounts->method('credentialFor')->willReturn('');
        $this->httpClient->expects($this->never())->method('get');

        $this->expectException(\RuntimeException::class);
        $this->service()->whoAmI('jdoe');
    }

    public function testDisconnectRevokesTheGrantOfAConnectedPerson(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jdoe');
        $this->oauth->method('isConnected')->with('jdoe')->willReturn(true);
        $this->oauth->expects($this->once())->method('revoke')->with('jdoe');
        $this->httpClient->expects($this->never())->method('delete');
        $this->userAccounts->expects($this->once())->method('deactivateUser')->with('jdoe');

        $this->service()->disconnect($user);
    }

    public function testDisconnectRevokesKeyThenClearsUser(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jdoe');
        $this->userAccounts->method('getStoredApiKeyId')->with('jdoe')->willReturn('44');
        $this->userAccounts->method('getStoredApiKey')->with('jdoe')->willReturn('sk_user');

        $resp = $this->createMock(IResponse::class);
        $resp->method('getBody')->willReturn('{"success":true}');
        $this->httpClient->expects($this->once())
            ->method('delete')
            ->with(
                'https://synaplan.example/api/v1/apikeys/44',
                $this->callback(fn (array $o): bool => ($o['headers']['X-API-Key'] ?? '') === 'sk_user')
            )
            ->willReturn($resp);
        $this->userAccounts->expects($this->once())->method('deactivateUser')->with('jdoe');

        $this->service()->disconnect($user);
    }

    public function testDisconnectKeepsUserWhenRevokeFails(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jdoe');
        $this->userAccounts->method('getStoredApiKeyId')->with('jdoe')->willReturn('44');
        $this->userAccounts->method('getStoredApiKey')->with('jdoe')->willReturn('sk_user');
        $this->httpClient->method('delete')->willThrowException(new \RuntimeException('HTTP 500'));
        $this->userAccounts->expects($this->never())->method('deactivateUser');

        $this->expectException(PlatformLinkException::class);
        $this->service()->disconnect($user);
    }

    public function testAnnounceNamesThisServerAndWhatTheAppDoes(): void
    {
        $this->synaplanConfig->method('getInstanceId')->willReturn('oc0abc');
        $this->appManager->method('getAppVersion')->willReturn('2.0.0');
        $this->oauth->method('isRegistered')->willReturn(true);
        $this->oauth->expects($this->once())->method('updateConfiguration')->with([
            'delivery_url' => 'http://nextcloud/index.php/apps/synaplan_integration/api/v1/inbound',
            'server_id' => 'oc0abc',
            'app_capabilities' => ['deliveries', 'pickups', 'read_scope', 'source_events', 'watches'],
            'software_version' => '2.0.0',
        ])->willReturn([]);
        $this->httpClient->expects($this->never())->method($this->anything());

        $this->service()->announceDelivery('http://nextcloud/index.php/apps/synaplan_integration/api/v1/inbound');
    }

    public function testWithWatchingOffTheAnnouncementLeavesOutWatches(): void
    {
        $this->config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = ''): string => $key === 'watch_folders' ? 'no' : $default,
        );
        $this->oauth->method('isRegistered')->willReturn(true);
        $this->oauth->expects($this->once())->method('updateConfiguration')->with($this->callback(
            static fn (array $body): bool => $body['app_capabilities'] === ['deliveries', 'pickups', 'read_scope', 'source_events'],
        ))->willReturn([]);

        $this->service()->announceDelivery('http://nextcloud/inbound');
    }

    public function testAnnounceWaitsForTheRegistration(): void
    {
        $this->oauth->method('isRegistered')->willReturn(false);
        $this->oauth->expects($this->never())->method('updateConfiguration');

        $this->service()->announceDelivery('http://nextcloud/inbound');
    }

    public function testACopyIsDownloadedFromItsOwnPathWithTimeForALargeFile(): void
    {
        $this->userAccounts->method('credentialFor')->willReturn('aaa.bbb.ccc');
        $resp = $this->createMock(IResponse::class);
        $resp->method('getStatusCode')->willReturn(200);
        $resp->method('getBody')->willReturn('bytes');
        $this->httpClient->expects($this->once())->method('get')
            ->with(
                'https://synaplan.example/api/v1/platform-links/deliveries/9/file?ticket=0f3a9c',
                $this->callback(fn (array $o): bool => $o['timeout'] >= 300),
            )
            ->willReturn($resp);

        self::assertSame('bytes', $this->service()->downloadDelivery('9', '/api/v1/platform-links/deliveries/9/file?ticket=0f3a9c', 'ada'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsOutsideTheCopy(): iterable
    {
        yield 'another copy' => ['/api/v1/platform-links/deliveries/10/file?ticket=0f3a9c'];
        yield 'another endpoint' => ['/api/v1/apikeys'];
        yield 'a longer id' => ['/api/v1/platform-links/deliveries/99/file?ticket=0f3a9c'];
        yield 'extra query' => ['/api/v1/platform-links/deliveries/9/file?ticket=0f3a9c&x=/api/v1/apikeys'];
        yield 'no ticket' => ['/api/v1/platform-links/deliveries/9/file?ticket='];
        yield 'an address' => ['https://evil.example/api/v1/platform-links/deliveries/9/file?ticket=0f3a9c'];
    }

    #[DataProvider('pathsOutsideTheCopy')]
    public function testADownloadOutsideTheCopysOwnPathIsRefused(string $path): void
    {
        $this->userAccounts->method('credentialFor')->willReturn('aaa.bbb.ccc');
        $this->httpClient->expects($this->never())->method('get');

        self::assertNull($this->service()->downloadDelivery('9', $path, 'ada'));
    }

    public function testACopyIsClaimedWithThePersonsOwnCredential(): void
    {
        $cases = [
            'sk_ada' => ['X-API-Key' => 'sk_ada'],
            'aaa.bbb.ccc' => ['Authorization' => 'Bearer aaa.bbb.ccc'],
        ];
        foreach ($cases as $stored => $expected) {
            $this->userAccounts = $this->createMock(UserAccountService::class);
            $this->userAccounts->method('credentialFor')->with('ada')->willReturn($stored);
            $resp = $this->createMock(IResponse::class);
            $resp->method('getStatusCode')->willReturn(200);
            $resp->method('getBody')->willReturn('{"success":true}');
            $this->httpClient = $this->createMock(IClient::class);
            $this->clientService = $this->createMock(IClientService::class);
            $this->clientService->method('newClient')->willReturn($this->httpClient);
            $this->httpClient->expects($this->once())
                ->method('post')
                ->with(
                    'https://synaplan.example/api/v1/platform-links/deliveries/9/claim',
                    $this->callback(function (array $options) use ($expected): bool {
                        foreach ($expected as $name => $value) {
                            if (($options['headers'][$name] ?? '') !== $value) {
                                return false;
                            }
                        }

                        return !isset($options['headers']['X-API-Key'], $options['headers']['Authorization'])
                            || count(array_intersect_key($options['headers'], ['X-API-Key' => true, 'Authorization' => true])) === 1;
                    })
                )
                ->willReturn($resp);

            $this->service()->claimDelivery('9', 'ada');
        }
    }

    public function testAFolderReadsClaimListingAndResultWaitNoLongerThanTheyAreGiven(): void
    {
        $this->userAccounts->method('credentialFor')->willReturn('sk_ada');
        $timeouts = [];
        $this->httpClient->expects($this->exactly(3))->method('post')->willReturnCallback(
            function (string $url, array $options) use (&$timeouts): IResponse {
                $timeouts[] = $options['timeout'];

                return $this->answer(200, '{}');
            },
        );

        $this->service()->claimPickup('15', 'ada', 12);
        $this->service()->submitPickupListing('15', 'ada', [], 3);
        $this->service()->reportPickupResult('15', 'ada', ['status' => 'done'], 4);

        self::assertSame([12, 3, 4], $timeouts);
    }

    public function testWatchesAreListedWithThePersonsOwnCredential(): void
    {
        $this->userAccounts->method('credentialFor')->with('ada')->willReturn('sk_ada');
        $this->httpClient->expects($this->once())->method('get')
            ->with('https://synaplan.example/api/v1/cloud/watches', $this->callback(
                static fn (array $options): bool => ($options['headers']['X-API-Key'] ?? '') === 'sk_ada',
            ))
            ->willReturn($this->answer(200, '{"items":[{"id":5,"target_kind":"folder","target_id":"30","state":"active"}],"paused_count":0}'));

        self::assertSame(
            [['id' => 5, 'target_kind' => 'folder', 'target_id' => '30', 'state' => 'active']],
            $this->service()->listWatches('ada'),
        );
    }

    public function testReconcileSendsIdsAndVersionsAndKeepsOnlyIds(): void
    {
        $this->userAccounts->method('credentialFor')->willReturn('sk_ada');
        $this->httpClient->expects($this->once())->method('post')
            ->with('https://synaplan.example/api/v1/cloud/watches/5/reconcile', $this->callback(
                static fn (array $options): bool => $options['body'] === '{"items":[{"id":"7","version":"e1"},{"id":"8","version":"e2"}]}',
            ))
            ->willReturn($this->answer(200, '{"process":["7",9],"forget":["8"]}'));

        self::assertSame(
            ['process' => ['7'], 'forget' => ['8']],
            $this->service()->reconcileWatch('ada', 5, [['id' => '7', 'version' => 'e1'], ['id' => '8', 'version' => 'e2']]),
        );
    }

    public function testAWatchFileGoesAsMultipartAndARefusalComesBackWithItsStatus(): void
    {
        $this->userAccounts->method('credentialFor')->willReturn('sk_ada');
        $this->httpClient->expects($this->once())->method('post')
            ->with('https://synaplan.example/api/v1/cloud/watches/5/files', $this->callback(
                static fn (array $options): bool => $options['http_errors'] === false
                    && $options['multipart'] === [
                        ['name' => 'id', 'contents' => '7'],
                        ['name' => 'version', 'contents' => 'e1'],
                        ['name' => 'file', 'contents' => 'hello', 'filename' => 'a.txt', 'headers' => ['Content-Type' => 'text/plain']],
                    ],
            ))
            ->willReturn($this->answer(409, '{"error":"invalid_watch","message":"This watch is not running."}'));

        $answer = $this->service()->sendWatchFile('ada', 5, ['id' => '7', 'version' => 'e1'], ['bytes' => 'hello', 'name' => 'a.txt', 'mime' => 'text/plain']);

        self::assertSame(409, $answer['status_code']);
        self::assertSame('invalid_watch', $answer['error']);
    }

    private function answer(int $status, string $body): IResponse
    {
        $response = $this->createStub(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);

        return $response;
    }

    public function testDisconnectUidClearsEvenWhenRevokeFails(): void
    {
        $this->userAccounts->method('getStoredApiKeyId')->with('jdoe')->willReturn('44');
        $this->userAccounts->method('getStoredApiKey')->with('jdoe')->willReturn('sk_user');
        $this->httpClient->method('delete')->willThrowException(new \RuntimeException('HTTP 500'));
        $this->userAccounts->expects($this->once())->method('deactivateUser')->with('jdoe');

        $this->service()->disconnectUid('jdoe');
    }
}
