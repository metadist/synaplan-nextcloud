<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use OCA\SynaplanIntegration\Controller\MediaController;
use OCA\SynaplanIntegration\Service\OwnWrites;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\SynaplanFolders;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MediaControllerTest extends TestCase
{
    /** @var array<string, Folder> folders by their path below the person's files */
    private array $folders = [];

    private string $copyFolder = '';

    public function testTheChatSavesIntoTheFolderThePersonChoseForFilesFromSynaplan(): void
    {
        $this->copyFolder = 'Projects/From AI';

        $response = $this->controller()->save('/api/v1/files/uploads/7/cat.png', 'cat.png');

        self::assertSame(200, $response->getStatus());
        self::assertSame(
            ['success' => true, 'path' => 'Projects/From AI/Images/cat.png', 'folder' => 'Projects/From AI/Images'],
            $response->getData(),
        );
    }

    public function testWithoutAChosenFolderTheChatSavesIntoSynaplan(): void
    {
        $response = $this->controller()->save('/api/v1/files/uploads/7/cat.png', 'cat.png');

        self::assertSame('Synaplan/Images/cat.png', $response->getData()['path'] ?? null);
    }

    private function controller(): MediaController
    {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('ada');
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $client = $this->createStub(SynaplanClient::class);
        $client->method('downloadFile')->willReturn('png bytes');
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($this->folder(''));
        $config = $this->createStub(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(
            fn (string $uid, string $app, string $key, string $default = ''): string => $key === SynaplanFolders::COPY_FOLDER ? $this->copyFolder : $default,
        );

        return new MediaController(
            $this->createStub(IRequest::class),
            $client,
            $root,
            $session,
            new NullLogger(),
            $this->createStub(OwnWrites::class),
            new SynaplanFolders($config, $this->createStub(OwnWrites::class)),
        );
    }

    private function folder(string $path): Folder
    {
        if (isset($this->folders[$path])) {
            return $this->folders[$path];
        }
        $folder = $this->createStub(Folder::class);
        $folder->method('nodeExists')->willReturn(false);
        $folder->method('getFreeSpace')->willReturn(-3);
        $folder->method('newFolder')->willReturnCallback(
            fn (string $name): Folder => $this->folder(ltrim($path . '/' . $name, '/')),
        );
        $folder->method('newFile')->willReturnCallback(function (string $name) use ($path): File {
            $file = $this->createStub(File::class);
            $file->method('getId')->willReturn(42);
            $file->method('getName')->willReturn($name);
            $file->method('getPath')->willReturn('/ada/files/' . ltrim($path . '/' . $name, '/'));

            return $file;
        });
        $folder->method('getRelativePath')->willReturnCallback(
            fn (string $absolute): string => substr($absolute, strlen('/ada/files')),
        );

        return $this->folders[$path] = $folder;
    }
}
