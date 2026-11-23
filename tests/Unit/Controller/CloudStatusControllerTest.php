<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use OCA\SynaplanIntegration\Controller\CloudStatusController;
use OCA\SynaplanIntegration\Service\CloudStateService;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\WatchPolicy;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class CloudStatusControllerTest extends TestCase
{
    public function testTheSearchableFilesAreTheNewestHundredFromNextcloud(): void
    {
        $client = $this->createMock(SynaplanClient::class);
        $client->expects(self::once())->method('listRecentFiles')
            ->with(1, 'nextcloud', '', 100)
            ->willReturn(['files' => [
                ['id' => 42, 'source' => 'nextcloud', 'source_id' => '7', 'filename' => 'brief.pdf', 'group_key' => 'Specs'],
            ]]);

        $file = $this->createStub(File::class);
        $file->method('getPath')->willReturn('/ada/files/Specs/brief.pdf');
        $file->method('getEtag')->willReturn('etag-1');
        $home = $this->createStub(Folder::class);
        $home->method('getFirstNodeById')->willReturnMap([[7, $file]]);
        $home->method('getRelativePath')->willReturn('/Specs/brief.pdf');
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($home);

        $cloudState = $this->createMock(CloudStateService::class);
        $cloudState->expects(self::once())->method('status')
            ->with([['id' => '7', 'version' => 'etag-1']])
            ->willReturn(['enabled' => true, 'items' => [['id' => '7', 'state' => 'vectorized']], 'watches' => []]);

        $config = $this->createStub(SynaplanConfig::class);
        $config->method('isPerUserAccountsEnabled')->willReturn(true);
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('ada');
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $controller = new CloudStatusController(
            $this->createStub(IRequest::class),
            $cloudState,
            $client,
            $config,
            $root,
            $session,
            $this->createStub(IURLGenerator::class),
            $this->createStub(WatchPolicy::class),
        );

        $files = $controller->searchable()->getData()['files'];

        self::assertSame(['Specs/brief.pdf'], array_column($files, 'path'));
    }
}
