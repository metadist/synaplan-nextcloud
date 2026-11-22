<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Listener;

use OCA\SynaplanIntegration\Listener\ReadScopeListener;
use OCA\SynaplanIntegration\Service\ReadScope;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ReadScopeListenerTest extends TestCase
{
    private ReadScope&MockObject $readScope;

    protected function setUp(): void
    {
        $this->readScope = $this->createMock(ReadScope::class);
    }

    public function testAMovedFolderBringsThePathsOfThePersonWhoMovedItUpToDate(): void
    {
        $this->readScope->expects($this->once())->method('follow')->with('ada');

        $this->rename($this->folder('/ada/files/Archive/Invoices', 'ada'));
    }

    public function testASharedFolderMovedByARecipientAlsoUpdatesTheOwner(): void
    {
        $people = [];
        $this->readScope->method('follow')->willReturnCallback(
            function (string $uid) use (&$people): bool {
                $people[] = $uid;

                return false;
            },
        );

        $this->rename($this->folder('/bob/files/From Ada/Invoices', 'ada'));

        self::assertSame(['bob', 'ada'], $people);
    }

    public function testARenamedFileChangesNoFolder(): void
    {
        $this->readScope->expects($this->never())->method('follow');
        $file = $this->createMock(File::class);
        $file->method('getPath')->willReturn('/ada/files/Invoices/a.txt');

        $this->rename($file);
    }

    public function testAFailedReportNeverBreaksTheRename(): void
    {
        $this->readScope->method('follow')->willThrowException(new \RuntimeException('down'));

        $this->rename($this->folder('/ada/files/Invoices', 'ada'));

        $this->addToAssertionCount(1);
    }

    private function rename(Node $target): void
    {
        $source = $this->createMock(Node::class);
        (new ReadScopeListener($this->readScope))->handle(new NodeRenamedEvent($source, $target));
    }

    private function folder(string $path, string $owner): Folder
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($owner);
        $folder = $this->createMock(Folder::class);
        $folder->method('getPath')->willReturn($path);
        $folder->method('getOwner')->willReturn($user);

        return $folder;
    }
}
