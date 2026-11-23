<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\BackgroundJob\PickupJob;
use OCA\SynaplanIntegration\Service\FolderRead;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\ReadScope;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FolderReadTest extends TestCase
{
    private PlatformLinkService&MockObject $links;
    private ReadScope&MockObject $scope;
    private IJobList&MockObject $jobs;
    private FolderRead $reads;
    private string $etag = 'etag-1';
    private int $etagReads = 0;
    /** @var array<string, array<string, mixed>> */
    private array $stored = [];
    /** @var list<\OCP\Files\Node> */
    private array $listing = [];

    protected function setUp(): void
    {
        $this->links = $this->createMock(PlatformLinkService::class);
        $this->links->method('canActFor')->willReturnCallback(static fn (string $uid): bool => $uid === 'ada');
        $this->scope = $this->createMock(ReadScope::class);
        $this->jobs = $this->createMock(IJobList::class);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(
            fn (string $uid): ?IUser => $uid === 'ada' ? $this->createMock(IUser::class) : null,
        );

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(7);
        $file->method('getName')->willReturn('a.txt');
        $file->method('getSize')->willReturn(5);
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getEtag')->willReturnCallback(function (): string {
            $this->etagReads++;

            return $this->etagReads === 1 ? 'etag-1' : $this->etag;
        });
        $file->method('getMTime')->willReturn(time());
        $file->method('getContent')->willReturn('hello');
        $file->method('getPath')->willReturn('/ada/files/Invoices/a.txt');

        $invoices = $this->createMock(Folder::class);
        $this->listing = [$file];
        $invoices->method('getDirectoryListing')->willReturnCallback(fn (): array => $this->listing);
        $invoices->method('getPath')->willReturn('/ada/files/Invoices');
        $invoices->method('getName')->willReturn('Invoices');

        $home = $this->createMock(Folder::class);
        $home->method('getPath')->willReturn('/ada/files');
        $home->method('get')->with('Invoices')->willReturn($invoices);
        // Nextcloud finds the home folder by its own id too.
        $home->method('getById')->willReturnCallback(static fn (int $id): array => match ($id) {
            7 => [$file],
            99 => [$home],
            default => [],
        });
        $home->method('getRelativePath')->willReturnCallback(static fn (string $path): string => match ($path) {
            '/ada/files/Invoices' => '/Invoices',
            '/ada/files/Invoices/Private' => '/Invoices/Private',
            '/ada/files/Invoices/a.txt' => '/Invoices/a.txt',
            default => '/',
        });

        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->with('ada')->willReturn($home);

        $appConfig = $this->createStub(IAppConfig::class);
        $appConfig->method('setValueArray')->willReturnCallback(function (string $app, string $key, array $value): bool {
            $this->stored[$key] = $value;

            return true;
        });
        $appConfig->method('getValueArray')->willReturnCallback(
            fn (string $app, string $key, array $default): array => $this->stored[$key] ?? $default,
        );
        $appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
            unset($this->stored[$key]);
        });

        $this->reads = new FolderRead(
            $this->links,
            $this->scope,
            $root,
            $users,
            $this->jobs,
            new NullLogger(),
            $appConfig,
        );
    }

    public function testAFolderOutsideTheSettingIsRefusedBeforeAnyListing(): void
    {
        $this->scope->method('mayRead')->with('ada', 'Invoices')->willReturn(false);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->expects($this->never())->method('submitPickupListing');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'failed' && $body['error_code'] === 'outside_scope',
        ));

        $this->reads->handle('15', 'ada');
    }

    public function testAReadIsNotClaimedWithoutThePersonsKey(): void
    {
        $this->links->expects($this->never())->method('claimPickup');

        $this->reads->handle('15', 'bob');
    }

    public function testAClaimNamingSomeoneElseIsRefusedBeforeAnyListing(): void
    {
        $claim = $this->claim();
        $claim['external_id'] = 'bob';
        $this->links->method('claimPickup')->willReturn($claim);
        $this->links->expects($this->never())->method('submitPickupListing');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'failed' && $body['error_code'] === 'not_found',
        ));

        $this->reads->handle('15', 'ada');
    }

    public function testAnAllowedFolderIsListedAndTheAskedFileIsSent(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->expects($this->once())->method('submitPickupListing')->with('15', 'ada', $this->callback(
            static fn (array $items): bool => ($items[0]['id'] ?? '') === '7' && ($items[0]['name'] ?? '') === 'a.txt',
        ))->willReturn(['upload' => ['7'], 'reuse' => [], 'skip' => []]);
        $this->links->expects($this->once())->method('uploadPickupFile')->with('15', 'ada', '7', 'etag-1', 'hello', 'a.txt', 'text/plain', $this->greaterThan(0))
            ->willReturn(['status_code' => 200]);
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'done' && $body['sent'] === 1 && $body['listed'] === 1,
        ));
        $this->jobs->expects($this->never())->method('add');

        $this->reads->handle('15', 'ada');
    }

    public function testARecursiveReadListsNothingFromASubFolderTheSettingDoesNotAllow(): void
    {
        $private = $this->createMock(Folder::class);
        $private->method('getPath')->willReturn('/ada/files/Invoices/Private');
        $private->method('getName')->willReturn('Private');
        $private->expects($this->never())->method('getDirectoryListing');
        $this->listing[] = $private;
        $this->scope->method('mayRead')->willReturnCallback(static fn (string $uid, string $folder): bool => $folder === 'Invoices');
        $claim = $this->claim();
        $claim['selector'] = ['folder_path' => 'Invoices', 'recursive' => true];
        $this->links->method('claimPickup')->willReturn($claim);
        $this->links->expects($this->once())->method('submitPickupListing')->with('15', 'ada', $this->callback(
            static fn (array $items): bool => array_column($items, 'id') === ['7'],
        ))->willReturn(['upload' => [], 'reuse' => [], 'skip' => []]);

        $this->reads->handle('15', 'ada');
    }

    public function testAFolderOfMoreThanFiveHundredFilesIsListedInFullInCallsOfFiveHundred(): void
    {
        $this->listing = [];
        for ($i = 1; $i <= 1201; $i++) {
            $file = $this->createStub(File::class);
            $file->method('getId')->willReturn(1000 + $i);
            $file->method('getName')->willReturn('f' . $i . '.txt');
            $file->method('getMTime')->willReturn(time());
            $file->method('getPath')->willReturn('/ada/files/Invoices/f' . $i . '.txt');
            $this->listing[] = $file;
        }
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $sizes = [];
        $this->links->expects($this->exactly(3))->method('submitPickupListing')->willReturnCallback(
            function (string $id, string $uid, array $items) use (&$sizes): array {
                $sizes[] = count($items);
                $last = $items[count($items) - 1]['id'];

                return ['upload' => [], 'reuse' => [], 'skip' => [['id' => $last, 'reason' => 'over_limit']]];
            },
        );
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['listed'] === 1201
                && array_column($body['skipped'], 'id') === ['1500', '2000', '2201'],
        ));

        $this->reads->handle('15', 'ada');

        self::assertSame([500, 500, 201], $sizes);
    }

    public function testAClaimedReadThatBreaksStillReportsAFailure(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willThrowException(new \RuntimeException('cURL error 28: timed out'));
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'failed' && $body['error_code'] === 'unreachable',
        ));

        $this->reads->handle('15', 'ada');
    }

    public function testAReadThatCannotBeClaimedReportsNothing(): void
    {
        $this->links->method('claimPickup')->willThrowException(new \RuntimeException('cURL error 7'));
        $this->links->expects($this->never())->method('reportPickupResult');

        $this->reads->handle('15', 'ada');
    }

    public function testAFileChangedSinceTheListingIsNotSentUnderItsOldVersion(): void
    {
        $this->etag = 'etag-2';
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturn(['upload' => ['7'], 'reuse' => [], 'skip' => []]);
        $this->links->expects($this->never())->method('uploadPickupFile');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'partial'
                && ($body['skipped'][0]['reason'] ?? '') === 'changed_since_listing',
        ));

        $this->reads->handle('15', 'ada');
    }

    public function testASelectorThatEscapesItsFolderIsRefused(): void
    {
        $claim = $this->claim();
        $claim['selector'] = ['folder_path' => 'Invoices/../HR', 'recursive' => false];
        $this->links->method('claimPickup')->willReturn($claim);
        $this->links->expects($this->never())->method('submitPickupListing');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'failed' && $body['error_code'] === 'not_found',
        ));

        $this->reads->handle('15', 'ada');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function selectorsNamingNoFolder(): iterable
    {
        yield 'nothing' => [['recursive' => true]];
        yield 'an empty path' => [['folder_path' => '', 'recursive' => true]];
        yield 'blanks' => [['folder_id' => '', 'folder_path' => '  ', 'recursive' => true]];
        yield 'the root' => [['folder_path' => '/', 'recursive' => true]];
        yield 'the root by its id' => [['folder_id' => '99', 'recursive' => true]];
    }

    /**
     * @param array<string, mixed> $selector
     */
    #[DataProvider('selectorsNamingNoFolder')]
    public function testASelectorNamingNoFolderIsRefusedEvenWhenAllFilesMayBeRead(array $selector): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $claim = $this->claim();
        $claim['selector'] = $selector;
        $claim['read_scope'] = ['mode' => 'all', 'folders' => []];
        $this->links->method('claimPickup')->willReturn($claim);
        $this->links->expects($this->never())->method('submitPickupListing');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'failed' && $body['error_code'] === 'not_found',
        ));

        $this->reads->handle('15', 'ada');
    }

    public function testAQueuedUploadRechecksTheSettingBeforeReading(): void
    {
        $this->scope->method('mayRead')->willReturn(false);
        $this->links->expects($this->never())->method('uploadPickupFile');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'partial'
                && ($body['skipped'][0]['reason'] ?? '') === 'outside_scope',
        ));

        $this->reads->finish('15', [
            'uid' => 'ada',
            'left' => [['id' => '7', 'version' => 'etag-1']],
            'sent' => 0,
            'listed' => 1,
            'skipped' => [],
        ]);
    }

    public function testUploadsLeftOverAreKeptOutOfTheJobArgumentAndFinishedLater(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturn(['upload' => ['7'], 'reuse' => [], 'skip' => []]);
        $this->jobs->expects($this->once())->method('add')->with(PickupJob::class, ['id' => '15']);
        $this->links->expects($this->once())->method('uploadPickupFile')->willReturn(['status_code' => 200]);
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'done' && $body['sent'] === 1 && $body['listed'] === 1,
        ));

        $this->reads->handle('15', 'ada', 1);
        $this->assertCount(1, $this->stored);

        $this->reads->finish('15');
        $this->assertSame([], $this->stored);

        $this->reads->finish('15');
    }

    public function testAFailedUploadClosesTheReadAsPartial(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturn(['upload' => ['7'], 'reuse' => [], 'skip' => []]);
        $this->links->method('uploadPickupFile')->willThrowException(new \RuntimeException('timed out'));
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'partial'
                && ($body['skipped'][0]['reason'] ?? '') === 'unreachable',
        ));

        $this->reads->handle('15', 'ada');
    }

    /**
     * @return array<string, mixed>
     */
    private function claim(): array
    {
        return [
            'external_id' => 'ada',
            'mode' => 'fetch',
            'selector' => ['folder_path' => 'Invoices', 'recursive' => false],
        ];
    }
}
