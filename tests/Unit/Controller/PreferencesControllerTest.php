<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use OCA\SynaplanIntegration\Controller\PreferencesController;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\SynaplanFolders;
use OCA\SynaplanIntegration\Service\ReadScope;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class PreferencesControllerTest extends TestCase
{
    /** @var array<string, string> */
    private array $stored = [];

    /** @var list<array{string, list<array{id: string, path: string}>}> */
    private array $scopes = [];

    /** @var list<string> the folders named through the folder menu */
    private array $allowed = [];

    private bool $readsFolders = true;

    public function testASynaplanThatReadsNoFoldersGetsNoReadSettingAndTheSavedOneStays(): void
    {
        $this->readsFolders = false;
        $this->stored[SynaplanFolders::COPY_FOLDER] = 'Projects';

        self::assertFalse($this->controller([])->get()->getData()['reads_folders']);

        $saved = $this->controller(['read_scope' => ['mode' => 'allowed', 'folders' => [['id' => '', 'path' => 'HR']]]])
            ->save('Elsewhere');
        self::assertSame(409, $saved->getStatus());
        self::assertSame('folder_reads_off', $saved->getData()['error']);
        self::assertSame('Projects', $this->stored[SynaplanFolders::COPY_FOLDER]);

        $allowed = $this->controller([])->allowFolder('Invoices');
        self::assertSame(409, $allowed->getStatus());
        self::assertSame('folder_reads_off', $allowed->getData()['error']);

        self::assertSame([], $this->scopes);
        self::assertSame([], $this->allowed);
    }

    public function testASynaplanThatReadsFoldersGetsTheReadSetting(): void
    {
        self::assertTrue($this->controller([])->get()->getData()['reads_folders']);
        self::assertSame(200, $this->controller([])->allowFolder('Invoices')->getStatus());
        self::assertSame(['Invoices'], $this->allowed);
    }

    public function testSavingOnlyTheReadSettingKeepsTheCopyFolder(): void
    {
        $this->stored[SynaplanFolders::COPY_FOLDER] = 'Projects/From AI';

        $response = $this->controller(['read_scope' => ['mode' => 'allowed', 'folders' => [['id' => '', 'path' => 'HR']]]])
            ->save();

        self::assertSame(200, $response->getStatus());
        self::assertSame('Projects/From AI', $this->stored[SynaplanFolders::COPY_FOLDER]);
        self::assertSame('Projects/From AI', $response->getData()['copy_folder']);
        self::assertSame([['allowed', [['id' => '', 'path' => 'HR']]]], $this->scopes);
    }

    public function testSavingTheCopyFolderAloneLeavesTheReadSetting(): void
    {
        $response = $this->controller([])->save('Projects/From AI');

        self::assertSame(200, $response->getStatus());
        self::assertSame('Projects/From AI', $this->stored[SynaplanFolders::COPY_FOLDER]);
        self::assertSame([], $this->scopes);
    }

    public function testARefusedFolderStoresNothing(): void
    {
        $this->stored[SynaplanFolders::COPY_FOLDER] = 'Projects';

        $response = $this->controller(['read_scope' => ['mode' => 'all', 'folders' => []]])->save('../elsewhere');

        self::assertSame(400, $response->getStatus());
        self::assertSame('Projects', $this->stored[SynaplanFolders::COPY_FOLDER]);
        self::assertSame([], $this->scopes);
    }

    /**
     * @param array<string, mixed> $params the decoded JSON body
     */
    private function controller(array $params): PreferencesController
    {
        $request = $this->createStub(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default,
        );
        $request->method('getParams')->willReturn($params);

        $config = $this->createStub(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(
            fn (string $uid, string $app, string $key, mixed $default = ''): string => $this->stored[$key] ?? (string) $default,
        );
        $config->method('setUserValue')->willReturnCallback(
            function (string $uid, string $app, string $key, string $value): void {
                $this->stored[$key] = $value;
            },
        );

        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('ada');
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $scope = $this->createStub(ReadScope::class);
        $scope->method('save')->willReturnCallback(
            function (string $uid, string $mode, array $folders): void {
                $this->scopes[] = [$mode, $folders];
            },
        );
        $scope->method('allow')->willReturnCallback(
            function (string $uid, string $path): ?string {
                $this->allowed[] = $path;

                return null;
            },
        );
        $scope->method('get')->willReturn(['mode' => 'watched', 'folders' => []]);
        $links = $this->createStub(PlatformLinkService::class);
        $links->method('readsFolders')->willReturnCallback(fn (): bool => $this->readsFolders);

        return new PreferencesController($request, $config, $session, $scope, $links);
    }
}
