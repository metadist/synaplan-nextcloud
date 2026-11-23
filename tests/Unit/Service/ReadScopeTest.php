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
use OCP\IConfig;
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

    private function scope(int $cap = 20): ReadScope
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => match ($key) {
                ReadScope::CAP => (string) $cap,
                default => $this->appValues[$key] ?? $default,
            },
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value): void {
                $this->appValues[$key] = $value;
            },
        );
        $config->method('getUserValue')->willReturnCallback(
            fn (string $uid, string $app, string $key): string => $this->stored[$uid . $key] ?? '',
        );
        $config->method('setUserValue')->willReturnCallback(
            function (string $uid, string $app, string $key, string $value): void {
                self::assertSame(Application::APP_ID, $app);
                $this->stored[$uid . $key] = $value;
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
        $userConfig->method('getValuesByUsers')->willReturnCallback(function (string $app, string $key): array {
            $values = [];
            foreach ($this->stored as $uidKey => $value) {
                if (str_ends_with($uidKey, $key)) {
                    $values[substr($uidKey, 0, -strlen($key))] = $value;
                }
            }

            return $values;
        });

        return new ReadScope($config, $links, $watches, $root, $userConfig);
    }

    private function folder(int $id, string $path): Folder
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getId')->willReturn($id);
        $folder->method('getPath')->willReturn('/ada/files/' . $path);

        return $folder;
    }
}
