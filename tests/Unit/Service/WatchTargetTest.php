<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\WatchTarget;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class WatchTargetTest extends TestCase
{
    public function testAReadableFileKeepsItsOwnPath(): void
    {
        $target = $this->target($this->file('Documents/Notes.md', Constants::PERMISSION_READ));

        self::assertSame([
            'target_kind' => 'file',
            'target_id' => '7',
            'target_path' => 'Documents/Notes.md',
        ], $target->resolve('file', '7'));
    }

    public function testAFolderIsAcceptedAsAFolder(): void
    {
        $node = $this->createStub(Folder::class);
        $node->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
        $node->method('getId')->willReturn(8);
        $node->method('getName')->willReturn('Invoices');
        $node->method('getPath')->willReturn('/ada/files/Invoices');

        self::assertSame('Invoices', $this->target($node, 'Invoices')->resolve('folder', '8')['target_path']);
    }

    public function testAnUnreadableNodeIsNotFound(): void
    {
        $target = $this->target($this->file('Secret.md', Constants::PERMISSION_SHARE));

        $this->expectException(NotFoundException::class);
        $target->resolve('file', '7');
    }

    public function testAKindThatDoesNotMatchTheNodeIsNotFound(): void
    {
        $target = $this->target($this->file('Notes.md', Constants::PERMISSION_READ));

        $this->expectException(NotFoundException::class);
        $target->resolve('folder', '7');
    }

    public function testAMissingNodeIsNotFound(): void
    {
        $target = $this->target(null);

        $this->expectException(NotFoundException::class);
        $target->resolve('file', '7');
    }

    private function file(string $relative, int $permissions): File
    {
        $file = $this->createStub(File::class);
        $file->method('getPermissions')->willReturn($permissions);
        $file->method('getId')->willReturn(7);
        $file->method('getName')->willReturn(basename($relative));
        $file->method('getPath')->willReturn('/ada/files/' . $relative);

        return $file;
    }

    private function target(File|Folder|null $node, string $relative = 'Documents/Notes.md'): WatchTarget
    {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('ada');
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $home = $this->createStub(Folder::class);
        $home->method('getFirstNodeById')->willReturn($node);
        $home->method('getRelativePath')->willReturn($relative);

        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($home);

        return new WatchTarget($root, $session);
    }
}
