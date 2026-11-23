<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\BackgroundJob\DeliveryRetryJob;
use OCA\SynaplanIntegration\Service\CopyDelivery;
use OCA\SynaplanIntegration\Service\OwnWrites;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CopyDeliveryTest extends TestCase
{
    private PlatformLinkService&MockObject $links;
    private Folder&MockObject $userFolder;
    private Folder&MockObject $dir;

    /** @var list<string> names that already exist in the target folder */
    private array $existing = [];
    private int $free = -3;
    private ?\Throwable $newFileFails = null;

    /** @var array<string, mixed>|null */
    private ?array $reported = null;

    private IJobList&MockObject $jobs;
    private int $now = 1_800_000_000;
    private string $cliUrl = '';

    /** @var array<string, mixed> */
    private array $notified = [];

    protected function setUp(): void
    {
        $this->jobs = $this->createMock(IJobList::class);
        $this->links = $this->createMock(PlatformLinkService::class);
        $this->links->method('reportDelivery')->willReturnCallback(
            function (string $id, string $uid, array $result): array {
                $this->reported = $result;

                return [];
            },
        );
        $this->dir = $this->createMock(Folder::class);
        $this->dir->method('nodeExists')->willReturnCallback(fn (string $name): bool => in_array($name, $this->existing, true));
        $this->dir->method('newFolder')->willReturnSelf();
        $this->dir->method('getFreeSpace')->willReturnCallback(fn (): int => $this->free);
        $this->dir->method('newFile')->willReturnCallback(function (string $name): File {
            if ($this->newFileFails !== null) {
                throw $this->newFileFails;
            }
            $file = $this->createMock(File::class);
            $file->method('getId')->willReturn(42);
            $file->method('getName')->willReturn($name);
            $file->method('getPath')->willReturn('/ada/files/Synaplan/Documents/' . $name);

            return $file;
        });
        $this->userFolder = $this->createMock(Folder::class);
        $this->userFolder->method('nodeExists')->willReturn(false);
        $this->userFolder->method('newFolder')->willReturn($this->dir);
        $this->userFolder->method('getRelativePath')->willReturnCallback(
            fn (string $path): string => substr($path, strlen('/ada/files')),
        );
    }

    public function testACopyIsWrittenAndReported(): void
    {
        $this->claim();

        $answer = $this->delivery()->handle('9', 'ada');

        self::assertSame(200, $answer['status']);
        self::assertSame('delivered', $answer['body']['status']);
        self::assertSame('/Synaplan/Documents/Q3 plan.docx', $answer['body']['remote_path']);
        self::assertSame($answer['body'], $this->reported);
    }

    public function testACopyOpensAtTheAddressPeopleUseNotTheOneSynaplanCalled(): void
    {
        $this->claim();
        $this->cliUrl = 'https://files.example.org/cloud/';

        $answer = $this->delivery()->handle('9', 'ada');

        self::assertSame('https://files.example.org/cloud/index.php/f/42', $answer['body']['open_url']);
        self::assertArrayNotHasKey('link', $this->notified);
    }

    public function testWithoutACommandLineAddressACopyOpensAtThisServersAddress(): void
    {
        $this->claim();

        $answer = $this->delivery()->handle('9', 'ada');

        self::assertSame('http://nextcloud/cloud/index.php/f/42', $answer['body']['open_url']);
    }

    public function testAFullStorageIsQuotaExceeded(): void
    {
        $this->claim();
        $this->free = 3;

        self::assertSame('quota_exceeded', $this->failure());
    }

    public function testAFolderThePersonMayNotWriteIsForbidden(): void
    {
        $this->claim();
        $this->newFileFails = new NotPermittedException('read-only share');

        self::assertSame('forbidden', $this->failure());
    }

    public function testATargetFolderThatIsGoneIsNotFound(): void
    {
        $this->claim(['target' => ['kind' => 'folder_id', 'ref' => '77']]);
        $this->userFolder->method('getById')->willReturn([]);

        self::assertSame('not_found', $this->failure());
    }

    public function testTwentyFilesWithTheSameNameAreAConflict(): void
    {
        $this->claim();
        $this->existing = ['Q3 plan.docx'];
        for ($n = 2; $n <= 20; $n++) {
            $this->existing[] = 'Q3 plan (' . $n . ').docx';
        }

        self::assertSame('conflict', $this->failure());
    }

    public function testNineteenFilesWithTheSameNameStillLeaveRoom(): void
    {
        $this->claim();
        $this->existing = ['Q3 plan.docx'];
        for ($n = 2; $n <= 19; $n++) {
            $this->existing[] = 'Q3 plan (' . $n . ').docx';
        }

        $answer = $this->delivery()->handle('9', 'ada');

        self::assertSame('/Synaplan/Documents/Q3 plan (20).docx', $answer['body']['remote_path']);
    }

    public function testAFileOverTheCapIsTooLargeAndNotDownloaded(): void
    {
        $this->claim(['size' => 100 * 1024 * 1024 + 1]);
        $this->links->expects($this->never())->method('downloadDelivery');

        self::assertSame('too_large', $this->failure());
    }

    public function testAnyOtherWriteErrorIsUnreachable(): void
    {
        $this->claim();
        $this->newFileFails = new \RuntimeException('storage offline');

        self::assertSame('unreachable', $this->failure());
    }

    public function testAFolderIsCleanedOrRefused(): void
    {
        self::assertSame('', CopyDelivery::cleanFolder(''));
        self::assertSame('', CopyDelivery::cleanFolder('   '));
        self::assertSame('', CopyDelivery::cleanFolder(' / '));
        self::assertSame('Projects/Q3', CopyDelivery::cleanFolder(' /Projects/Q3/ '));
        self::assertSame('Projects/Q3', CopyDelivery::cleanFolder('Projects\\Q3'));
        foreach (['Projects/ /Q3', 'Projects//Q3', 'Projects/../HR', './Q3'] as $refused) {
            try {
                CopyDelivery::cleanFolder($refused);
                self::fail($refused . ' was accepted');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testAFailedClaimIsTriedAgainInTheBackground(): void
    {
        $this->links->method('claimDelivery')->willThrowException(new \RuntimeException('connection refused'));
        $this->links->expects($this->never())->method('reportDelivery');
        $this->jobs->expects($this->once())->method('add')
            ->with(DeliveryRetryJob::class, ['id' => '9', 'uid' => 'ada', 'since' => $this->now]);

        $answer = $this->delivery()->handle('9', 'ada');

        self::assertSame(202, $answer['status']);
        self::assertSame('pending', $answer['body']['state']);
    }

    public function testTheBackgroundTryWritesTheCopyAndReportsIt(): void
    {
        $this->claim();
        $this->jobs->expects($this->never())->method('add');

        $this->delivery()->retry('9', 'ada', $this->now - 240);

        self::assertSame('delivered', $this->reported['status'] ?? null);
    }

    public function testTheBackgroundTryIsTheLastOne(): void
    {
        $this->links->expects($this->once())->method('claimDelivery')->willThrowException(new \RuntimeException('connection refused'));
        $this->jobs->expects($this->never())->method('add');

        $this->delivery()->retry('9', 'ada', $this->now - 60);
    }

    public function testAnExpiredCopyIsNotClaimedAgain(): void
    {
        $this->links->expects($this->never())->method('claimDelivery');

        $this->delivery()->retry('9', 'ada', $this->now - 301);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function claim(array $override = []): void
    {
        $this->links->method('claimDelivery')->willReturn($override + [
            'id' => '9',
            'external_id' => 'ada',
            'name' => 'Q3 plan.docx',
            'size' => 5,
            'target' => ['kind' => 'kind_folder', 'ref' => ''],
            'on_conflict' => 'rename',
            'download_path' => '/api/v1/platform-links/deliveries/9/file?ticket=abc',
        ]);
        $this->links->method('downloadDelivery')->willReturn('hello');
    }

    private function failure(): string
    {
        $answer = $this->delivery()->handle('9', 'ada');
        self::assertSame('failed', $answer['body']['status']);
        self::assertSame($answer['body'], $this->reported);

        return (string) $answer['body']['error_code'];
    }

    private function delivery(): CopyDelivery
    {
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($this->userFolder);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('');
        $caches = $this->createMock(ICacheFactory::class);
        $caches->method('createDistributed')->willReturn($this->createMock(ICache::class));
        $config->method('getSystemValueString')->willReturnCallback(
            fn (string $key, string $default = ''): string => $key === 'overwrite.cli.url' ? $this->cliUrl : $default,
        );
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturn('http://nextcloud/cloud/index.php/f/42');
        $urls->method('linkToRoute')->willReturn('/cloud/index.php/f/42');
        $urls->method('getWebroot')->willReturn('/cloud');
        $notification = $this->createMock(INotification::class);
        foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $setter) {
            $notification->method($setter)->willReturnSelf();
        }
        $notification->method('setSubject')->willReturnCallback(
            function (string $subject, array $parameters) use ($notification): INotification {
                $this->notified = $parameters;

                return $notification;
            },
        );
        $notifications = $this->createMock(IManager::class);
        $notifications->method('createNotification')->willReturn($notification);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);

        return new CopyDelivery(
            $this->links,
            $root,
            $users,
            $config,
            $caches,
            $urls,
            $notifications,
            new NullLogger(),
            $this->jobs,
            $time,
            $this->createStub(OwnWrites::class),
        );
    }
}
