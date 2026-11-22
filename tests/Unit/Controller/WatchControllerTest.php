<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use OCA\SynaplanIntegration\Controller\WatchController;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\WatchIndex;
use OCA\SynaplanIntegration\Service\WatchPolicy;
use OCA\SynaplanIntegration\Service\WatchTarget;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class WatchControllerTest extends TestCase
{
    private SynaplanClient&MockObject $client;
    private Folder&MockObject $home;
    private LoggerInterface&MockObject $logger;
    private WatchIndex&MockObject $index;
    private WatchController $controller;

    protected function setUp(): void
    {
        $this->client = $this->createMock(SynaplanClient::class);
        $this->home = $this->createMock(Folder::class);
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($this->home);
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('ada');
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->index = $this->createMock(WatchIndex::class);
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(1700000000);
        $this->controller = new WatchController(
            $this->createStub(IRequest::class),
            $this->client,
            new WatchTarget($root, $session, $this->createStub(WatchPolicy::class)),
            $this->logger,
            $this->index,
            $session,
            $time,
        );
    }

    /**
     * @return iterable<string, array{string, string, ?int}>
     */
    public static function choicesTheDialogNeverOffers(): iterable
    {
        yield 'an unknown target kind' => ['share', 'keep_searchable', null];
        yield 'an unknown preset' => ['folder', 'delete_everything', null];
        yield 'a task preset without a task' => ['folder', 'task', null];
        yield 'a task preset with no real task' => ['file', 'task', 0];
    }

    #[DataProvider('choicesTheDialogNeverOffers')]
    public function testAChoiceTheDialogNeverOffersIsABadRequest(string $kind, string $preset, ?int $taskId): void
    {
        $this->home->expects($this->never())->method('getFirstNodeById');
        $this->client->expects($this->never())->method('startWatch');

        $response = $this->controller->start($kind, '42', $preset, $taskId);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('unexpected', $response->getData()['error']);
    }

    public function testStartsAWatchOnTheResolvedNode(): void
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getId')->willReturn(42);
        $folder->method('getPermissions')->willReturn(Constants::PERMISSION_READ | Constants::PERMISSION_CREATE);
        $folder->method('getPath')->willReturn('/ada/files/Projects');
        $this->home->method('getFirstNodeById')->with(42)->willReturn($folder);
        $this->home->method('getRelativePath')->willReturn('/Projects');
        $this->client->expects($this->once())->method('startWatch')
            ->with(['target_kind' => 'folder', 'target_id' => '42', 'target_path' => 'Projects', 'preset' => 'task', 'task_id' => 7])
            ->willReturn(['id' => 3]);

        $this->index->expects($this->once())->method('remember')->with('ada', ['id' => 3], 1700000000);

        $response = $this->controller->start('folder', '42', 'task', 7);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['id' => 3], $response->getData()['watch']);
    }

    public function testAskingForTheFilesAlreadyThereFeedsTheWatchFromTheBeginning(): void
    {
        $this->home->method('getFirstNodeById')->willReturn($this->readableFolder());
        $this->home->method('getRelativePath')->willReturn('/Projects');
        $this->client->method('startWatch')->willReturn(['id' => 3]);
        $this->index->expects($this->once())->method('remember')->with('ada', ['id' => 3], 0);

        $this->controller->start('folder', '42', 'keep_searchable', null, 'Projects', true);
    }

    public function testOptionsCountTheFilesDirectlyInTheFolder(): void
    {
        $folder = $this->readableFolder();
        $folder->method('getDirectoryListing')->willReturn([
            $this->createStub(File::class),
            $this->createStub(File::class),
            $this->createStub(Folder::class),
        ]);
        $this->home->method('getFirstNodeById')->willReturn($folder);
        $this->home->method('getRelativePath')->willReturn('/Projects');
        $this->client->method('watchOptions')->willReturn(['tasks' => []]);

        $this->assertSame(2, $this->controller->options('42')->getData()['existing']);
    }

    public function testOptionsForAFolderThePersonMayOnlyReadSayWhyBeforeTheDialogOffersAnything(): void
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
        $this->home->method('getFirstNodeById')->willReturn($folder);
        $this->client->expects($this->never())->method('watchOptions');

        $response = $this->controller->options('42');

        $this->assertSame(403, $response->getStatus());
        $this->assertSame('watch_needs_edit', $response->getData()['error']);
    }

    private function readableFolder(): Folder&\PHPUnit\Framework\MockObject\Stub
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getId')->willReturn(42);
        $folder->method('getPermissions')->willReturn(Constants::PERMISSION_READ | Constants::PERMISSION_CREATE);
        $folder->method('getPath')->willReturn('/ada/files/Projects');

        return $folder;
    }

    public function testANodeThePersonCannotReadIsNotFoundAndLoggedAtInfo(): void
    {
        $this->home->method('getFirstNodeById')->willReturn(null);
        $this->logger->expects($this->once())->method('info');
        $this->logger->expects($this->never())->method('error');

        $response = $this->controller->start('file', '9', 'keep_searchable');

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('file_not_found', $response->getData()['error']);
    }

    public function testAnUnreachableSynaplanIsLoggedAsAnErrorWithoutItsText(): void
    {
        $this->client->method('watchOptions')
            ->willThrowException(new \RuntimeException('cURL error 7: Failed to connect to backend port 80'));
        $this->logger->expects($this->once())->method('error');

        $response = $this->controller->options();
        $data = $response->getData();

        $this->assertSame(502, $response->getStatus());
        $this->assertSame('synaplan_unreachable', $data['error']);
        $this->assertSame([], $data['tasks']);
        $this->assertStringNotContainsString('backend', (string) json_encode($data));
    }
}
