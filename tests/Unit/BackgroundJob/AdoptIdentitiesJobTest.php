<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\BackgroundJob;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\BackgroundJob\AdoptIdentitiesJob;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdoptIdentitiesJobTest extends TestCase
{
    private IConfig&MockObject $config;
    private SynaplanConfig&MockObject $synaplanConfig;
    private UserAccountService&MockObject $accounts;
    private IUserManager&MockObject $users;

    /** @var array<string, string> */
    private array $values = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default): string => $this->values[$key] ?? $default,
        );
        $this->config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
            self::assertSame(Application::APP_ID, $app);
            $this->values[$key] = $value;
        });
        $this->synaplanConfig = $this->createMock(SynaplanConfig::class);
        $this->accounts = $this->createMock(UserAccountService::class);
        $this->users = $this->createMock(IUserManager::class);
        $this->users->method('search')->willReturnCallback(
            fn (string $pattern, int $limit, int $offset): array => $offset === 0 ? [$this->user('alice'), $this->user('bob')] : [],
        );
    }

    public function testEveryPersonIsAdoptedOnceTheNextcloudIsRegistered(): void
    {
        $this->registered();
        $adopted = [];
        $this->accounts->expects($this->exactly(2))->method('adoptLegacyAccount')
            ->willReturnCallback(function (string $uid) use (&$adopted): bool {
                $adopted[] = $uid;

                return true;
            });

        $this->runJob();
        $this->runJob();

        self::assertSame(['alice', 'bob'], $adopted);
        self::assertSame('1', $this->values[AdoptIdentitiesJob::DONE]);
    }

    public function testAPassWithAFailureRunsAgain(): void
    {
        $this->registered();
        $this->accounts->method('adoptLegacyAccount')->willReturnCallback(static fn (string $uid): bool => $uid !== 'bob');

        $this->runJob();

        self::assertArrayNotHasKey(AdoptIdentitiesJob::DONE, $this->values);
    }

    public function testNothingRunsBeforeTheRegistration(): void
    {
        $this->synaplanConfig->method('getLinkInstanceId')->willReturn('');
        $this->synaplanConfig->method('getAdminApiKey')->willReturn('sk_admin');
        $this->accounts->expects($this->never())->method('adoptLegacyAccount');

        $this->runJob();

        self::assertArrayNotHasKey(AdoptIdentitiesJob::DONE, $this->values);
    }

    private function registered(): void
    {
        $this->synaplanConfig->method('getLinkInstanceId')->willReturn('pi_nc');
        $this->synaplanConfig->method('getAdminApiKey')->willReturn('sk_admin');
    }

    private function user(string $uid): IUser
    {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return $user;
    }

    private function runJob(): void
    {
        $job = new AdoptIdentitiesJob(
            $this->createStub(ITimeFactory::class),
            $this->config,
            $this->synaplanConfig,
            $this->accounts,
            $this->users,
        );
        (new \ReflectionMethod(AdoptIdentitiesJob::class, 'run'))->invoke($job, null);
    }
}
