<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\BackgroundJob;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\BackgroundJob\AdoptIdentitiesJob;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Tests\Unit\Support\KeyStorageStubs;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AdoptIdentitiesJobTest extends TestCase
{
    use KeyStorageStubs;

    private PlatformLinkService&MockObject $links;
    private SynaplanConfig&MockObject $synaplanConfig;
    private IUserManager&MockObject $users;
    private IConfig&MockObject $config;
    private LoggerInterface&MockObject $logger;

    /** @var array<string, string> */
    private array $userConfig = [];
    /** @var array<string, string> */
    private array $appConfig = [];

    protected function setUp(): void
    {
        $this->links = $this->createMock(PlatformLinkService::class);
        $this->synaplanConfig = $this->createMock(SynaplanConfig::class);
        $this->users = $this->createMock(IUserManager::class);
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->synaplanConfig->method('getLinkInstanceId')->willReturn('pi_nc');
        $this->synaplanConfig->method('getLinkInstanceSecretCipher')->willReturn('enc:secret');
        $this->links->method('capabilities')->willReturn(['identity_adopt', 'lifecycle']);

        $this->config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $this->appConfig[$key] ?? $default
        );
        $this->config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value): void {
                $this->appConfig[$key] = $value;
            }
        );
        $this->config->method('getUserValue')->willReturnCallback(
            fn (string $uid, string $app, string $key, string $default = ''): string => $this->userConfig[$uid . '|' . $key] ?? $default
        );
    }

    private function job(): AdoptIdentitiesJob
    {
        return new AdoptIdentitiesJob(
            $this->createStub(ITimeFactory::class),
            $this->links,
            $this->synaplanConfig,
            $this->keyStorageFrom($this->config),
            $this->users,
            $this->config,
            $this->logger,
        );
    }

    private function user(string $uid): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return $user;
    }

    public function testAdoptsPeopleWithAKeyAndASecondRunDoesNothing(): void
    {
        $this->userConfig['ada|synaplan_user_api_key'] = 'sk_ada';
        $this->userConfig['bob|synaplan_user_api_key'] = '';
        $this->users->method('search')->willReturn([$this->user('ada'), $this->user('bob')]);
        $this->links->expects($this->once())->method('adoptIdentity')->with('ada', 'sk_ada')->willReturn(200);

        $job = $this->job();
        $this->runJob($job);
        $this->assertSame('1', $this->appConfig[AdoptIdentitiesJob::DONE]);

        $this->runJob($job);
    }

    public function testSkipsWhenSynaplanCannotAdoptYet(): void
    {
        $this->links = $this->createMock(PlatformLinkService::class);
        $this->links->method('capabilities')->willReturn(['deliveries']);
        $this->links->expects($this->never())->method('adoptIdentity');
        $this->users->expects($this->never())->method('search');

        $this->runJob($this->job());
        $this->assertArrayNotHasKey(AdoptIdentitiesJob::DONE, $this->appConfig);
    }

    public function testStopsOn403WithOneLogLine(): void
    {
        $this->userConfig['ada|synaplan_user_api_key'] = 'sk_ada';
        $this->userConfig['carol|synaplan_user_api_key'] = 'sk_carol';
        $this->users->method('search')->willReturn([$this->user('ada'), $this->user('carol')]);
        $this->links->expects($this->once())->method('adoptIdentity')->with('ada', 'sk_ada')->willReturn(403);
        $this->logger->expects($this->once())->method('error');

        $this->runJob($this->job());
        $this->assertArrayNotHasKey(AdoptIdentitiesJob::DONE, $this->appConfig);
    }

    private function runJob(AdoptIdentitiesJob $job): void
    {
        $method = new \ReflectionMethod(AdoptIdentitiesJob::class, 'run');
        $method->invoke($job, null);
    }
}
