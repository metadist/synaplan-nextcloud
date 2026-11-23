<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\ReadScope;
use OCA\SynaplanIntegration\Service\WatchIndex;
use OCP\Config\IUserConfig;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

final class ReadScopeTest extends TestCase
{
    /** @var array<string, string> uid . key => value */
    private array $stored = [];

    /** @var array<string, string> */
    private array $appValues = [];

    /** @var array<string, int> folder path => file id */
    private array $folders = [];

    /** @var list<list<array{id: string, path: string}>> what Synaplan was told */
    private array $reports = [];

    /** @var list<array{id: int, uid: string, kind: string, target: int, state: string, since: int, reconciled: int}> */
    private array $watches = [];

    /** @var array<string, array{shared: int, exclusive: bool}> lock => holders */
    private array $locks = [];

    /** @var (callable(): void)|null what another request does when this one has to wait */
    private $whileWaiting = null;

    public function testWatchedAllowsTheFoldersThePersonWatchesAndNothingBelowOrBeside(): void
    {
        $this->folders = ['Incoming-DE' => 30, 'HR' => 9];
        $this->watches = [
            ['id' => 5, 'uid' => 'ada', 'kind' => 'folder', 'target' => 30, 'state' => 'active', 'since' => 0, 'reconciled' => 0],
            ['id' => 6, 'uid' => 'ada', 'kind' => 'file', 'target' => 31, 'state' => 'active', 'since' => 0, 'reconciled' => 0],
        ];
        $scope = $this->scope();
        $scope->save('ada', ReadScope::MODE_WATCHED, [['id' => '9', 'path' => 'HR']]);

        self::assertTrue($scope->mayRead('ada', 'Incoming-DE'));
        self::assertFalse($scope->mayRead('ada', 'Incoming-DE/2026'));
        self::assertFalse($scope->mayRead('ada', 'HR'));
        self::assertFalse($scope->mayRead('ada', 'Specs/brief.pdf'));
    }

    public function testAWatchedFolderStaysReadableWhenThePersonAllowsAFolderToo(): void
    {
        $this->folders = ['Invoices' => 30, 'HR' => 9];
        $this->watches = [
            ['id' => 5, 'uid' => 'ada', 'kind' => 'folder', 'target' => 30, 'state' => 'active', 'since' => 0, 'reconciled' => 0],
        ];
        $scope = $this->scope();
        self::assertTrue($scope->mayRead('ada', 'Invoices'));

        self::assertNull($scope->allow('ada', '9', 'HR'));

        self::assertSame(ReadScope::MODE_ALLOWED, $scope->get('ada')['mode']);
        self::assertTrue($scope->mayRead('ada', 'Invoices'));
        self::assertFalse($scope->mayRead('ada', 'Invoices/2026'));
        self::assertTrue($scope->mayRead('ada', 'HR'));
    }

    public function testNothingAllowedMeansNothingMayBeRead(): void
    {
        $scope = $this->scope();

        self::assertFalse($scope->mayRead('ada', 'Invoices'));
        self::assertSame(ReadScope::MODE_WATCHED, $scope->get('ada')['mode']);
    }

    public function testANamedFolderAndWhatIsInsideItMayBeRead(): void
    {
        $scope = $this->scope();
        self::assertNull($scope->allow('ada', '3', 'Invoices'));

        self::assertTrue($scope->mayRead('ada', 'Invoices'));
        self::assertTrue($scope->mayRead('ada', 'Invoices/2026'));
        self::assertFalse($scope->mayRead('ada', 'HR'));
        self::assertSame(ReadScope::MODE_ALLOWED, $scope->get('ada')['mode']);
    }

    public function testTheAdministratorsCapStopsAnotherFolder(): void
    {
        $scope = $this->scope(1);
        self::assertNull($scope->allow('ada', '3', 'Invoices'));

        self::assertSame('cap', $scope->allow('ada', '4', 'HR'));
        self::assertCount(1, $scope->get('ada')['folders']);
    }

    public function testNamingAnAlreadyNamedFolderUnderWatchedOpensIt(): void
    {
        $scope = $this->scope();
        self::assertNull($scope->allow('ada', '3', 'Invoices'));
        $scope->save('ada', ReadScope::MODE_WATCHED, [['id' => '3', 'path' => 'Invoices']]);
        self::assertFalse($scope->mayRead('ada', 'Invoices'));

        self::assertNull($scope->allow('ada', '3', 'Invoices'));

        self::assertSame(ReadScope::MODE_ALLOWED, $scope->get('ada')['mode']);
        self::assertTrue($scope->mayRead('ada', 'Invoices'));
    }

    public function testAllFilesMayBeReadWhenTheAdministratorAllowsIt(): void
    {
        $scope = $this->scope();
        $scope->setCeiling(ReadScope::MODE_ALL);
        $scope->save('ada', ReadScope::MODE_ALL, []);

        self::assertTrue($scope->mayRead('ada', 'HR/Contracts'));
    }

    public function testByDefaultNobodyMayChooseAllTheirFiles(): void
    {
        $scope = $this->scope();
        self::assertSame(ReadScope::MODE_ALLOWED, $scope->ceiling());

        $scope->save('ada', ReadScope::MODE_ALL, [['id' => '', 'path' => 'Invoices']]);

        self::assertSame(ReadScope::MODE_ALLOWED, $scope->get('ada')['mode']);
        self::assertTrue($scope->mayRead('ada', 'Invoices'));
        self::assertFalse($scope->mayRead('ada', 'HR/Contracts'));
    }

    public function testLoweringTheCeilingNarrowsEveryoneAboveItAndRaisingItWidensNobody(): void
    {
        $scope = $this->scope();
        $scope->setCeiling(ReadScope::MODE_ALL);
        $scope->save('ada', ReadScope::MODE_ALL, []);
        $scope->save('bob', ReadScope::MODE_ALLOWED, [['id' => '', 'path' => 'HR']]);
        $scope->save('carol', ReadScope::MODE_WATCHED, []);

        self::assertSame(2, $scope->setCeiling(ReadScope::MODE_WATCHED));

        self::assertSame('1', $this->stored['ada' . ReadScope::PENDING] ?? null);
        self::assertSame('1', $this->stored['bob' . ReadScope::PENDING] ?? null);
        self::assertArrayNotHasKey('carol' . ReadScope::PENDING, $this->stored);
        self::assertSame('HR', $scope->get('bob')['folders'][0]['path']);

        self::assertSame(0, $scope->setCeiling(ReadScope::MODE_ALL));

        self::assertSame(ReadScope::MODE_WATCHED, $scope->get('ada')['mode']);
        self::assertSame(ReadScope::MODE_WATCHED, $scope->get('bob')['mode']);
        self::assertFalse($scope->mayRead('ada', 'HR/Contracts'));
    }

    public function testUnderTheWatchedCeilingNoFolderCanBeAllowed(): void
    {
        $scope = $this->scope();
        $scope->setCeiling(ReadScope::MODE_WATCHED);

        self::assertSame('ceiling', $scope->allow('ada', '3', 'Invoices'));
        self::assertSame(ReadScope::MODE_WATCHED, $scope->get('ada')['mode']);
        self::assertSame([], $scope->get('ada')['folders']);
    }

    public function testLoweringTheCeilingWaitsForAChangeUnderWayAndKeepsIt(): void
    {
        $this->folders = ['HR' => 4];
        $scope = $this->scope();
        $scope->setCeiling(ReadScope::MODE_ALL);
        $scope->save('ada', ReadScope::MODE_ALL, [['id' => '', 'path' => 'HR']]);

        // Ada's other request is switching to "Only what I watch" and
        // dropping HR when the administrator lowers the ceiling.
        $ceilingLock = Application::APP_ID . '/read-scope-ceiling';
        $adaLock = Application::APP_ID . '/read-scope/' . md5('ada');
        $this->locks[$ceilingLock] = ['shared' => 1, 'exclusive' => false];
        $this->locks[$adaLock] = ['shared' => 0, 'exclusive' => true];
        $this->whileWaiting = function () use ($ceilingLock, $adaLock): void {
            $this->stored['ada' . ReadScope::PREF] = (string) json_encode(['mode' => ReadScope::MODE_WATCHED, 'folders' => []]);
            unset($this->locks[$ceilingLock], $this->locks[$adaLock]);
        };

        self::assertSame(0, $this->scope()->setCeiling(ReadScope::MODE_ALLOWED));

        self::assertSame(
            ['mode' => ReadScope::MODE_WATCHED, 'folders' => []],
            json_decode($this->stored['ada' . ReadScope::PREF], true),
        );
    }

    public function testAChoiceSavedAfterTheCeilingWasLoweredIsHeldToIt(): void
    {
        $this->appValues[ReadScope::CEILING] = ReadScope::MODE_ALL;
        $scope = $this->scope();
        self::assertSame(ReadScope::MODE_ALL, $scope->ceiling());

        // The administrator lowers it while Ada's request is running.
        $this->appValues[ReadScope::CEILING] = ReadScope::MODE_ALLOWED;
        $scope->save('ada', ReadScope::MODE_ALL, []);

        $this->appValues[ReadScope::CEILING] = ReadScope::MODE_ALL;
        self::assertSame(ReadScope::MODE_ALLOWED, $this->scope()->get('ada')['mode']);
    }

    public function testAPersonWithALongUserIdCanChangeTheReadSetting(): void
    {
        // user_oidc's unique ids are 64-character hashes.
        $uid = str_repeat('f', 64);
        $scope = $this->scope();
        $scope->save($uid, ReadScope::MODE_WATCHED, []);

        self::assertSame(ReadScope::MODE_WATCHED, $scope->get($uid)['mode']);
    }

    public function testSynaplanHearsOfTheNamedFoldersOnlyUnderAlsoFoldersIAllow(): void
    {
        $this->folders = ['HR' => 4];
        $this->appValues[ReadScope::CEILING] = ReadScope::MODE_ALL;
        $scope = $this->scope();
        $scope->save('ada', ReadScope::MODE_ALLOWED, [['id' => '', 'path' => 'HR']]);
        $scope->save('ada', ReadScope::MODE_WATCHED, [['id' => '4', 'path' => 'HR']]);
        $scope->save('ada', ReadScope::MODE_ALL, [['id' => '4', 'path' => 'HR']]);

        self::assertSame([[['id' => '4', 'path' => 'HR']], [], []], $this->reports);
        self::assertSame([['id' => '4', 'path' => 'HR']], $scope->get('ada')['folders']);
    }

    public function testAnAllowedFolderKeepsItsPermissionWhenRenamed(): void
    {
        $this->folders = ['Invoices' => 3];
        $scope = $this->scope();
        $scope->save('ada', ReadScope::MODE_ALLOWED, [['id' => '', 'path' => 'Invoices']]);
        self::assertSame('3', $scope->get('ada')['folders'][0]['id']);

        $this->folders = ['Archive/Invoices' => 3];
        $renamed = $this->scope();

        self::assertTrue($renamed->mayRead('ada', 'Archive/Invoices/2026'));
        self::assertFalse($renamed->mayRead('ada', 'Invoices'));
    }

    public function testANewFolderAtAnAllowedNameIsNotAllowed(): void
    {
        $this->folders = ['Invoices' => 3];
        $this->scope()->allow('ada', '99', 'Invoices');
        self::assertSame('3', $this->scope()->get('ada')['folders'][0]['id']);

        $this->folders = ['Invoices' => 8];

        self::assertFalse($this->scope()->mayRead('ada', 'Invoices'));
    }

    public function testAFolderSavedWithoutAnIdIsMatchedByPath(): void
    {
        $this->stored['ada' . ReadScope::PREF] = (string) json_encode([
            'mode' => ReadScope::MODE_ALLOWED,
            'folders' => [['id' => '', 'path' => 'Invoices']],
        ]);

        self::assertTrue($this->scope()->mayRead('ada', 'Invoices/2026'));
    }

    public function testAMovedAllowedFolderIsReportedAtItsNewPath(): void
    {
        $this->folders = ['Invoices' => 3, 'HR' => 4];
        $this->scope()->save('ada', ReadScope::MODE_ALLOWED, [
            ['id' => '', 'path' => 'Invoices'],
            ['id' => '', 'path' => 'HR'],
        ]);
        $this->reports = [];
        $this->folders = ['Archive/Invoices 2026' => 3, 'HR' => 4];

        self::assertTrue($this->scope()->follow('ada'));

        $expected = [['id' => '3', 'path' => 'Archive/Invoices 2026'], ['id' => '4', 'path' => 'HR']];
        self::assertSame([$expected], $this->reports);
        self::assertSame($expected, $this->scope()->get('ada')['folders']);
    }

    public function testNothingIsReportedWhenNoAllowedFolderMoved(): void
    {
        $this->folders = ['Invoices' => 3];
        $this->scope()->save('ada', ReadScope::MODE_ALLOWED, [['id' => '', 'path' => 'Invoices']]);
        $this->reports = [];
        $this->folders = ['Invoices' => 3, 'Other' => 9];

        self::assertFalse($this->scope()->follow('ada'));
        self::assertSame([], $this->reports);
    }

    public function testAFolderThatIsGoneOrHasNoIdKeepsItsPath(): void
    {
        $this->stored['ada' . ReadScope::PREF] = (string) json_encode([
            'mode' => ReadScope::MODE_ALLOWED,
            'folders' => [['id' => '3', 'path' => 'Invoices'], ['id' => '', 'path' => 'Old']],
        ]);

        self::assertFalse($this->scope()->follow('ada'));
        self::assertSame([], $this->reports);
        self::assertSame('Invoices', $this->scope()->get('ada')['folders'][0]['path']);
    }

    public function testAFollowSeesAMoveMadeAfterAnEarlierReadInTheSameRequest(): void
    {
        $this->folders = ['Invoices' => 3];
        $scope = $this->scope();
        $scope->save('ada', ReadScope::MODE_ALLOWED, [['id' => '', 'path' => 'Invoices']]);
        self::assertTrue($scope->mayRead('ada', 'Invoices'));
        $this->folders = ['Archive/Invoices' => 3];

        self::assertTrue($scope->follow('ada'));
        self::assertSame('Archive/Invoices', $scope->get('ada')['folders'][0]['path']);
    }

    public function testALongBackgroundRunSeesAFolderTakenBackAndALoweredCeiling(): void
    {
        $this->folders = ['Invoices' => 3, 'HR' => 4];
        $this->appValues[ReadScope::CEILING] = ReadScope::MODE_ALL;
        $this->scope()->save('ada', ReadScope::MODE_ALLOWED, [
            ['id' => '', 'path' => 'Invoices'],
            ['id' => '', 'path' => 'HR'],
        ]);
        $job = $this->scope();
        self::assertTrue($job->mayRead('ada', 'HR'));

        // Ada takes HR back in her browser while the job is sending files.
        $this->stored['ada' . ReadScope::PREF] = (string) json_encode([
            'mode' => ReadScope::MODE_ALLOWED,
            'folders' => [['id' => '3', 'path' => 'Invoices']],
        ]);
        self::assertFalse($job->mayRead('ada', 'HR'));

        // Then she picks "All my files", and the administrator lowers the
        // ceiling in another request.
        $this->stored['ada' . ReadScope::PREF] = (string) json_encode([
            'mode' => ReadScope::MODE_ALL,
            'folders' => [['id' => '3', 'path' => 'Invoices']],
        ]);
        self::assertTrue($job->mayRead('ada', 'Specs'));
        $this->appValues[ReadScope::CEILING] = ReadScope::MODE_ALLOWED;
        self::assertFalse($job->mayRead('ada', 'Specs'));
    }

    public function testEachCheckFindsTheAllowedFolderWhereItIsNow(): void
    {
        $this->folders = ['Invoices' => 3];
        $this->scope()->save('ada', ReadScope::MODE_ALLOWED, [['id' => '', 'path' => 'Invoices']]);
        $job = $this->scope();
        self::assertTrue($job->mayRead('ada', 'Invoices'));

        $this->folders = ['Archive/Invoices' => 3];

        self::assertFalse($job->mayRead('ada', 'Invoices'));
        self::assertTrue($job->mayRead('ada', 'Archive/Invoices'));
    }

    private function scope(int $cap = 20): ReadScope
    {
        // What this request has read of the app config, until it clears it.
        $appCache = null;
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            function (string $app, string $key, string $default = '') use ($cap, &$appCache): string {
                $appCache ??= $this->appValues;

                return $key === ReadScope::CAP ? (string) $cap : ($appCache[$key] ?? $default);
            },
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value) use (&$appCache): void {
                $this->appValues[$key] = $value;
                if ($appCache !== null) {
                    $appCache[$key] = $value;
                }
            },
        );
        $appConfig = $this->createStub(IAppConfig::class);
        $appConfig->method('clearCache')->willReturnCallback(function () use (&$appCache): void {
            $appCache = null;
        });
        // What this request has read of each person's settings, until it
        // clears them; another process writes $this->stored directly.
        $userCache = [];
        $config->method('getUserValue')->willReturnCallback(
            function (string $uid, string $app, string $key) use (&$userCache): string {
                $userCache[$uid] ??= [];

                return $userCache[$uid][$key] ??= $this->stored[$uid . $key] ?? '';
            },
        );
        $config->method('setUserValue')->willReturnCallback(
            function (string $uid, string $app, string $key, string $value) use (&$userCache): void {
                self::assertSame(Application::APP_ID, $app);
                $this->stored[$uid . $key] = $value;
                $userCache[$uid][$key] = $value;
            },
        );
        $config->method('deleteUserValue')->willReturnCallback(
            function (string $uid, string $app, string $key) use (&$userCache): void {
                unset($this->stored[$uid . $key], $userCache[$uid][$key]);
            },
        );
        $links = $this->createMock(PlatformLinkService::class);
        $links->method('reportReadScope')->willReturnCallback(
            function (string $uid, string $mode, array $folders): bool {
                $this->reports[] = $folders;

                return true;
            },
        );

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->willReturnCallback(function (string $path): Folder {
            if (!isset($this->folders[$path])) {
                throw new NotFoundException($path);
            }

            return $this->folder($this->folders[$path], $path);
        });
        $userFolder->method('getById')->willReturnCallback(function (int $id): array {
            $path = array_search($id, $this->folders, true);

            return $path === false ? [] : [$this->folder($id, $path)];
        });
        $userFolder->method('getFirstNodeById')->willReturnCallback(function (int $id): ?Folder {
            $path = array_search($id, $this->folders, true);

            return $path === false ? null : $this->folder($id, $path);
        });
        $userFolder->method('getRelativePath')->willReturnCallback(
            fn (string $path): string => substr($path, strlen('/ada/files')),
        );
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($userFolder);

        $watches = $this->createStub(WatchIndex::class);
        $watches->method('rowsOf')->willReturnCallback(fn (): array => $this->watches);

        $userConfig = $this->createStub(IUserConfig::class);
        $userConfig->method('clearCache')->willReturnCallback(function (string $uid) use (&$userCache): void {
            unset($userCache[$uid]);
        });
        $userConfig->method('clearCacheAll')->willReturnCallback(function () use (&$userCache): void {
            $userCache = [];
        });
        $userConfig->method('getValuesByUsers')->willReturnCallback(function (string $app, string $key): array {
            $values = [];
            foreach ($this->stored as $uidKey => $value) {
                if (str_ends_with($uidKey, $key)) {
                    $values[substr($uidKey, 0, -strlen($key))] = $value;
                }
            }

            return $values;
        });

        return new ReadScope($config, $links, $watches, $root, $userConfig, $appConfig, $this->lockingProvider());
    }

    private function lockingProvider(): ILockingProvider
    {
        $locks = $this->createStub(ILockingProvider::class);
        $locks->method('acquireLock')->willReturnCallback(function (string $path, int $type): void {
            // Nextcloud's database locking keeps the key in a varchar(64)
            // and reads a failed insert as a lock that is taken.
            if (strlen($path) > 64) {
                throw new LockedException($path);
            }
            $held = $this->locks[$path] ?? ['shared' => 0, 'exclusive' => false];
            $free = $type === ILockingProvider::LOCK_SHARED
                ? !$held['exclusive']
                : !$held['exclusive'] && $held['shared'] === 0;
            if (!$free) {
                $other = $this->whileWaiting;
                $this->whileWaiting = null;
                if ($other !== null) {
                    $other();
                }
                throw new LockedException($path);
            }
            if ($type === ILockingProvider::LOCK_SHARED) {
                $held['shared']++;
            } else {
                $held['exclusive'] = true;
            }
            $this->locks[$path] = $held;
        });
        $locks->method('releaseLock')->willReturnCallback(function (string $path, int $type): void {
            $held = $this->locks[$path] ?? ['shared' => 0, 'exclusive' => false];
            if ($type === ILockingProvider::LOCK_SHARED) {
                $held['shared'] = max(0, $held['shared'] - 1);
            } else {
                $held['exclusive'] = false;
            }
            $this->locks[$path] = $held;
        });

        return $locks;
    }

    private function folder(int $id, string $path): Folder
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getId')->willReturn($id);
        $folder->method('getPath')->willReturn('/ada/files/' . $path);

        return $folder;
    }
}
