<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\ReadScope;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class ReadScopeTest extends TestCase
{
    /** @var array<string, string> */
    private array $stored = [];

    /** @var array<string, int> folder path => file id */
    private array $folders = [];

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

    public function testAllFilesMayBeRead(): void
    {
        $scope = $this->scope();
        $scope->save('ada', ReadScope::MODE_ALL, []);

        self::assertTrue($scope->mayRead('ada', 'HR/Contracts'));
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

    private function scope(int $cap = 20): ReadScope
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn((string) $cap);
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
        $links->expects($this->any())->method('reportReadScope');

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
        $userFolder->method('getRelativePath')->willReturnCallback(
            fn (string $path): string => substr($path, strlen('/ada/files')),
        );
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($userFolder);

        return new ReadScope($config, $links, $root);
    }

    private function folder(int $id, string $path): Folder
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getId')->willReturn($id);
        $folder->method('getPath')->willReturn('/ada/files/' . $path);

        return $folder;
    }
}
