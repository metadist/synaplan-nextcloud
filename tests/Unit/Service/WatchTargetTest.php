<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Exception\FailureException;
use OCA\SynaplanIntegration\Http\Failure;
use OCA\SynaplanIntegration\Service\WatchPolicy;
use OCA\SynaplanIntegration\Service\WatchTarget;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class WatchTargetTest extends TestCase
{
    public function testAReadableFileKeepsItsOwnPath(): void
    {
        $target = $this->target($this->file('Documents/Notes.md', Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE));

        self::assertSame([
            'target_kind' => 'file',
            'target_id' => '7',
            'target_path' => 'Documents/Notes.md',
        ], $target->resolve('file', '7'));
    }

    public function testALongPathIsCutOnACharacterBoundary(): void
    {
        $relative = str_repeat('ä', 200) . '.md';
        $target = $this->target($this->file($relative, Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE), $relative);

        $path = $target->resolve('file', '7')['target_path'];

        self::assertLessThanOrEqual(255, strlen($path));
        self::assertTrue(mb_check_encoding($path, 'UTF-8'));
        self::assertNotFalse(json_encode(['target_path' => $path]));
    }

    public function testAFolderIsAcceptedAsAFolder(): void
    {
        $node = $this->createStub(Folder::class);
        $node->method('getPermissions')->willReturn(Constants::PERMISSION_READ | Constants::PERMISSION_CREATE);
        $node->method('getId')->willReturn(8);
        $node->method('getName')->willReturn('Invoices');
        $node->method('getPath')->willReturn('/ada/files/Invoices');

        self::assertSame('Invoices', $this->target($node, 'Invoices')->resolve('folder', '8')['target_path']);
    }

    public function testAnUnreadableNodeSaysThereIsNoAccess(): void
    {
        $target = $this->target($this->file('Secret.md', Constants::PERMISSION_SHARE));

        try {
            $target->resolve('file', '7');
            self::fail('A file without read permission was accepted');
        } catch (FailureException $e) {
            self::assertSame(403, $e->failure->status);
            self::assertSame(Failure::NO_ACCESS, $e->failure->code);
        }
    }

    public function testAHiddenNodeSaysThereIsNoAccess(): void
    {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('ada');
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $home = $this->createStub(Folder::class);
        $home->method('getFirstNodeById')->willThrowException(new NotPermittedException());
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($home);
        $policy = $this->createStub(WatchPolicy::class);

        try {
            (new WatchTarget($root, $session, $policy))->resolve('file', '7');
            self::fail('A hidden file was accepted');
        } catch (FailureException $e) {
            self::assertSame(Failure::NO_ACCESS, $e->failure->code);
        }
    }

    public function testAFileThePersonMayOnlyReadCannotBeWatched(): void
    {
        $target = $this->target($this->file('Shared/Notes.md', Constants::PERMISSION_READ | Constants::PERMISSION_SHARE));

        try {
            $target->resolve('file', '7');
            self::fail('A read-only file was accepted');
        } catch (FailureException $e) {
            self::assertSame(403, $e->failure->status);
            self::assertSame(Failure::WATCH_NEEDS_EDIT, $e->failure->code);
        }
    }

    public function testAFolderThePersonMayOnlyReadCannotBeWatched(): void
    {
        $node = $this->createStub(Folder::class);
        $node->method('getPermissions')->willReturn(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_DELETE);
        $node->method('getId')->willReturn(8);
        $node->method('getPath')->willReturn('/ada/files/Shared');

        $this->expectException(FailureException::class);
        $this->target($node, 'Shared')->resolve('folder', '8');
    }

    public function testASharedTargetCannotBeWatchedWhileTheAdministratorTurnedThatOff(): void
    {
        $target = $this->target($this->file('Shared/Notes.md', Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE), 'Shared/Notes.md', true);

        try {
            $target->resolve('file', '7');
            self::fail('A shared file was accepted');
        } catch (FailureException $e) {
            self::assertSame(403, $e->failure->status);
            self::assertSame(Failure::WATCH_SHARED_OFF, $e->failure->code);
        }
    }

    public function testNothingCanBeWatchedWhileTheAdministratorTurnedWatchingOff(): void
    {
        $target = $this->target($this->file('Notes.md', Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE), 'Notes.md', watchingOff: true);

        try {
            $target->resolve('file', '7');
            self::fail('A file was accepted with watching off');
        } catch (FailureException $e) {
            self::assertSame(403, $e->failure->status);
            self::assertSame(Failure::WATCHES_OFF, $e->failure->code);
        }
    }

    public function testAKindThatDoesNotMatchTheNodeIsNotFound(): void
    {
        $target = $this->target($this->file('Notes.md', Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE));

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

    private function target(File|Folder|null $node, string $relative = 'Documents/Notes.md', bool $sharedOff = false, bool $watchingOff = false): WatchTarget
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

        $policy = $this->createStub(WatchPolicy::class);
        $policy->method('heldBy')->willReturn($watchingOff ? WatchPolicy::OFF_WATCHES : ($sharedOff ? WatchPolicy::OFF_SHARED : null));

        return new WatchTarget($root, $session, $policy);
    }
}
