<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\WatchPolicy;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WatchPolicyTest extends TestCase
{
    /** @var array<int, array<string, mixed>> the access list of each folder stub */
    private array $access = [];

    public function testWatchingSharedFoldersIsAllowedUntilTheAdministratorTurnsItOff(): void
    {
        self::assertTrue($this->policy(null)->sharedAllowed());
        self::assertFalse($this->policy('no')->sharedAllowed());
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function jobModes(): iterable
    {
        yield 'never set, which Nextcloud reads as AJAX' => [null, false];
        yield 'AJAX' => ['ajax', false];
        yield 'webcron' => ['webcron', true];
        yield 'cron' => ['cron', true];
    }

    #[DataProvider('jobModes')]
    public function testOnlyAjaxLeavesBackgroundJobsWithoutASchedule(?string $mode, bool $scheduled): void
    {
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default): string => $app === 'core' && $key === 'backgroundjobs_mode' && $mode !== null ? $mode : $default,
        );
        $policy = new WatchPolicy($config, $this->createStub(IShareManager::class), $this->createStub(IRootFolder::class));

        self::assertSame($scheduled, $policy->jobsOnSchedule());
        self::assertSame($mode ?? 'ajax', WatchPolicy::jobsModeIn($config));
        self::assertSame($scheduled, WatchPolicy::jobsOnScheduleIn($config));
    }

    public function testAFolderSharedWithThePersonIsShared(): void
    {
        $folder = $this->folder(received: true, access: ['users' => ['bob']]);

        self::assertTrue($this->policy('no')->isShared($folder));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function accessLists(): iterable
    {
        yield 'only the owner' => [['users' => ['ada'], 'remote' => false, 'public' => false, 'mail' => []], false];
        yield 'another person' => [['users' => ['ada', 'bob']], true];
        yield 'the owner twice' => [['users' => ['ada', 'ada']], false];
        yield 'a public link' => [['users' => ['ada'], 'public' => true], true];
        yield 'another server' => [['users' => ['ada'], 'remote' => true], true];
        yield 'a mail share' => [['users' => ['ada'], 'mail' => ['x@example.org']], true];
    }

    /**
     * @param array<string, mixed> $access
     */
    #[DataProvider('accessLists')]
    public function testAFolderThePersonSharesIsShared(array $access, bool $shared): void
    {
        self::assertSame($shared, $this->policy('no')->isShared($this->folder(received: false, access: $access)));
    }

    public function testOnlyASharedTargetIsBlockedAndOnlyWhileTheSettingIsOff(): void
    {
        $shared = $this->folder(received: true, access: []);
        $own = $this->folder(received: false, access: ['users' => ['ada']]);

        self::assertTrue($this->policy('no')->blocks($shared));
        self::assertFalse($this->policy('no')->blocks($own));
        self::assertFalse($this->policy('yes')->blocks($shared));
    }

    public function testTheWatchesOnSharedTargetsAreMarkedStopped(): void
    {
        $nodes = [
            30 => $this->folder(received: true, access: []),
            31 => $this->folder(received: false, access: ['users' => ['ada']]),
        ];
        $watches = [
            ['id' => 1, 'target_id' => '30'],
            ['id' => 2, 'target_id' => '31'],
            ['id' => 3, 'target_id' => '99'],
        ];

        $marked = $this->policy('no', $nodes)->markStopped('ada', $watches);

        self::assertTrue($marked[0]['policy_off'] ?? false);
        self::assertArrayNotHasKey('policy_off', $marked[1]);
        self::assertArrayNotHasKey('policy_off', $marked[2]);
        self::assertSame($watches, $this->policy('yes', $nodes)->markStopped('ada', $watches));
    }

    /**
     * @param array<string, mixed> $access
     */
    private function folder(bool $received, array $access): Folder
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('isShared')->willReturn($received);
        $this->access[spl_object_id($folder)] = $access;

        return $folder;
    }

    /**
     * @param array<int, Folder> $nodes
     */
    private function policy(?string $value, array $nodes = []): WatchPolicy
    {
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = ''): string => $value ?? $default,
        );
        $shares = $this->createStub(IShareManager::class);
        $shares->method('getAccessList')->willReturnCallback(
            fn (Folder $node): array => $this->access[spl_object_id($node)] ?? [],
        );
        $home = $this->createStub(Folder::class);
        $home->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?Folder => $nodes[$id] ?? null);
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($home);

        return new WatchPolicy($config, $shares, $root);
    }
}
