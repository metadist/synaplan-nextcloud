<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\ShareRecipients;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager;
use PHPUnit\Framework\TestCase;

final class ShareRecipientsTest extends TestCase
{
    /**
     * @param list<array{int, string}> $mounts storage and root path of each of Bob's mounts
     */
    private function recipients(IManager $shares, bool $credential = true, array $mounts = []): ShareRecipients
    {
        $accounts = $this->createStub(UserAccountService::class);
        $accounts->method('hasStoredCredential')->willReturnCallback(static fn (string $uid): bool => $credential && $uid !== 'dan');
        $cache = $this->createStub(IUserMountCache::class);
        $cache->method('getMountsForUser')->willReturn(array_map(function (array $mount): ICachedMountInfo {
            $info = $this->createStub(ICachedMountInfo::class);
            $info->method('getStorageId')->willReturn($mount[0]);
            $info->method('getRootInternalPath')->willReturn($mount[1]);

            return $info;
        }, $mounts));
        $users = $this->createStub(IUserManager::class);
        $users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $uid === 'bob' ? $this->createStub(IUser::class) : null);

        return new ShareRecipients($shares, $accounts, $cache, $users);
    }

    public function testThePeopleWithAccessAndACredentialWhoWereNotToldYet(): void
    {
        $shares = $this->createStub(IManager::class);
        $shares->method('getAccessList')->willReturn(['users' => ['ada', 'bob', 'carol', 'dan', 'bob'], 'public' => false]);

        self::assertSame(['bob', 'carol'], $this->recipients($shares)->of($this->createStub(Node::class), ['ada']));
    }

    public function testAnAccessListThatFailsTellsNobodyElse(): void
    {
        $shares = $this->createStub(IManager::class);
        $shares->method('getAccessList')->willThrowException(new \RuntimeException('no share provider'));

        self::assertSame([], $this->recipients($shares, false)->of($this->createStub(Node::class), []));
    }

    public function testSomeoneLosesANodeWhenTheCurrentAccessLeavesThemOut(): void
    {
        $shares = $this->createMock(IManager::class);
        $shares->method('getAccessList')->with(self::anything(), true, true)
            ->willReturn(['users' => ['ada' => ['node_id' => 50, 'node_path' => '/Specs'], 'carol' => ['node_id' => 50, 'node_path' => '/Specs']], 'public' => false]);
        $recipients = $this->recipients($shares);
        $node = $this->createStub(Node::class);

        self::assertTrue($recipients->lost($node, 'bob'));
        self::assertFalse($recipients->lost($node, 'carol'), 'Carol still reaches it, through another share or a group');
        self::assertFalse($recipients->lost($node, 'dan'), 'Dan cannot tell Synaplan');
    }

    public function testNobodyLosesANodeWhenTheAccessListFails(): void
    {
        $shares = $this->createStub(IManager::class);
        $shares->method('getAccessList')->willThrowException(new \RuntimeException('no share provider'));

        self::assertFalse($this->recipients($shares)->lost($this->createStub(Node::class), 'bob'));
    }

    public function testTheFoldersBelowAFolderSomeoneStillHasMountedOnTheirOwn(): void
    {
        $recipients = $this->recipients($this->createStub(IManager::class), true, [
            [3, 'files/Specs/Old'],
            [3, 'files/Specs'],
            [3, 'files/Specsheet'],
            [4, 'files/Specs/New'],
            [7, ''],
        ]);

        self::assertSame(['files/Specs/Old'], $recipients->keptBelow('bob', ['storage' => 3, 'path' => 'files/Specs']));
        self::assertSame([], $recipients->keptBelow('eve', ['storage' => 3, 'path' => 'files/Specs']));
    }
}
