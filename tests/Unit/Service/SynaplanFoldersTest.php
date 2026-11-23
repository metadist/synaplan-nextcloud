<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\OwnWrites;
use OCA\SynaplanIntegration\Service\SynaplanFolders;
use OCP\Files\AlreadyExistsException;
use OCP\Files\EntityTooLargeException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotEnoughSpaceException;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class SynaplanFoldersTest extends TestCase
{
    public function testAFolderIsCleanedOrRefused(): void
    {
        self::assertSame('', SynaplanFolders::cleanFolder(''));
        self::assertSame('', SynaplanFolders::cleanFolder('   '));
        self::assertSame('', SynaplanFolders::cleanFolder(' / '));
        self::assertSame('Projects/Q3', SynaplanFolders::cleanFolder(' /Projects/Q3/ '));
        self::assertSame('Projects/Q3', SynaplanFolders::cleanFolder('Projects\\Q3'));
        foreach (['Projects/ /Q3', 'Projects//Q3', 'Projects/../HR', './Q3'] as $refused) {
            try {
                SynaplanFolders::cleanFolder($refused);
                self::fail($refused . ' was accepted');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testATakenNameGetsANumberUntilTwentyFilesHaveIt(): void
    {
        $taken = ['cat.png'];
        for ($n = 2; $n <= 19; $n++) {
            $taken[] = 'cat (' . $n . ').png';
        }
        self::assertSame('cat (20).png', $this->folders()->write($this->folder($taken), 'cat.png', 'png')->getName());

        $taken[] = 'cat (20).png';
        $this->expectException(AlreadyExistsException::class);
        $this->folders()->write($this->folder($taken), 'cat.png', 'png');
    }

    public function testAWriteThatFailsLeavesNoEmptyFile(): void
    {
        $file = $this->createMock(File::class);
        $file->method('putContent')->willThrowException(new \RuntimeException('storage offline'));
        $file->expects(self::once())->method('delete');
        $folder = $this->createStub(Folder::class);
        $folder->method('getFreeSpace')->willReturn(-3);
        $folder->method('newFile')->willReturn($file);

        $this->expectExceptionMessage('storage offline');
        $this->folders()->write($folder, 'cat.png', 'png');
    }

    public function testAFileMustFitTheStorageAndTheLimit(): void
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getFreeSpace')->willReturn(2);

        try {
            $this->folders()->write($folder, 'cat.png', 'png');
            self::fail('A file larger than the free space was written.');
        } catch (NotEnoughSpaceException) {
        }
        $this->expectException(EntityTooLargeException::class);
        $this->folders()->assertFits($this->folder([]), SynaplanFolders::MAX_BYTES + 1);
    }

    private function folders(): SynaplanFolders
    {
        return new SynaplanFolders($this->createStub(IConfig::class), $this->createStub(OwnWrites::class));
    }

    /**
     * @param list<string> $taken
     */
    private function folder(array $taken): Folder
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getFreeSpace')->willReturn(-3);
        $folder->method('nodeExists')->willReturnCallback(fn (string $name): bool => in_array($name, $taken, true));
        $folder->method('newFile')->willReturnCallback(function (string $name): File {
            $file = $this->createStub(File::class);
            $file->method('getName')->willReturn($name);

            return $file;
        });

        return $folder;
    }
}
