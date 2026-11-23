<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\LibraryImport;
use OCA\SynaplanIntegration\Service\OwnWrites;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\SynaplanFolders;
use OCP\Files\AlreadyExistsException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotEnoughSpaceException;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LibraryImportTest extends TestCase
{
    private SynaplanClient&MockObject $client;

    /** @var list<string> */
    private array $taken = [];
    private int $free = -3;
    private ?File $written = null;

    protected function setUp(): void
    {
        $this->client = $this->createMock(SynaplanClient::class);
    }

    public function testFromSynaplanReadsAtMostWhatACopyMayBe(): void
    {
        $this->client->expects(self::once())->method('downloadStoredFile')
            ->with(7, SynaplanFolders::MAX_BYTES)
            ->willReturn('png');

        self::assertSame(['name' => 'cat.png', 'folder' => 'Photos'], $this->import()->copy('ada', 7, 'Photos', 'cat.png'));
    }

    public function testFromSynaplanStopsAtTwentyFilesOfOneName(): void
    {
        $this->client->method('downloadStoredFile')->willReturn('png');
        $this->taken = ['cat.png'];
        for ($n = 2; $n <= 20; $n++) {
            $this->taken[] = 'cat (' . $n . ').png';
        }

        $this->expectException(AlreadyExistsException::class);
        $this->import()->copy('ada', 7, 'Photos', 'cat.png');
    }

    public function testFromSynaplanChecksTheFreeSpaceFirst(): void
    {
        $this->client->method('downloadStoredFile')->willReturn('png');
        $this->free = 2;

        try {
            $this->import()->copy('ada', 7, 'Photos', 'cat.png');
            self::fail('A file larger than the free space was written.');
        } catch (NotEnoughSpaceException) {
        }
        self::assertNull($this->written);
    }

    private function import(): LibraryImport
    {
        $photos = $this->createStub(Folder::class);
        $photos->method('getFreeSpace')->willReturnCallback(fn (): int => $this->free);
        $photos->method('nodeExists')->willReturnCallback(fn (string $name): bool => in_array($name, $this->taken, true));
        $photos->method('newFile')->willReturnCallback(function (string $name): File {
            $file = $this->createStub(File::class);
            $file->method('getName')->willReturn($name);
            $file->method('getPath')->willReturn('/ada/files/Photos/' . $name);

            return $this->written = $file;
        });
        $home = $this->createStub(Folder::class);
        $home->method('nodeExists')->willReturn(true);
        $home->method('get')->willReturn($photos);
        $home->method('getRelativePath')->willReturnCallback(
            fn (string $absolute): string => substr($absolute, strlen('/ada/files')),
        );
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($home);

        return new LibraryImport(
            $this->client,
            $root,
            new NullLogger(),
            new SynaplanFolders($this->createStub(IConfig::class), $this->createStub(OwnWrites::class)),
        );
    }
}
