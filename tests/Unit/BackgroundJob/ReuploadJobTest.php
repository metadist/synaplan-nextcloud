<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\BackgroundJob;

use OCA\SynaplanIntegration\BackgroundJob\ReuploadJob;
use OCA\SynaplanIntegration\Service\CloudStateService;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ReuploadJobTest extends TestCase
{
    private UserAccountService&MockObject $userAccounts;
    private CloudStateService&MockObject $cloudState;
    private SynaplanClient&MockObject $client;
    private ReuploadJob $job;

    protected function setUp(): void
    {
        $file = $this->createStub(File::class);
        $file->method('getSize')->willReturn(12);
        $file->method('getEtag')->willReturn('e2');
        $file->method('getName')->willReturn('spec.pdf');
        $file->method('getPath')->willReturn('/ada/files/Specs/spec.pdf');
        $file->method('getContent')->willReturn('new version');
        $home = $this->createStub(Folder::class);
        $home->method('getFirstNodeById')->willReturn($file);
        $home->method('getRelativePath')->willReturn('/Specs/spec.pdf');
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($home);

        $this->userAccounts = $this->createMock(UserAccountService::class);
        $this->cloudState = $this->createMock(CloudStateService::class);
        $this->client = $this->createMock(SynaplanClient::class);
        $this->job = new ReuploadJob(
            $this->createStub(ITimeFactory::class),
            $this->userAccounts,
            $root,
            $this->cloudState,
            $this->client,
            new NullLogger(),
        );
    }

    public function testAConnectedPersonsFileIsSentWithTheirAccessToken(): void
    {
        $this->userAccounts->method('credentialFor')->with('ada')->willReturn('oauth.access.token');
        $this->cloudState->method('status')
            ->with([['id' => '42', 'version' => 'e2']], 'oauth.access.token')
            ->willReturn(['items' => [[
                'state' => 'indexed',
                'kept_up_to_date' => true,
                'knowledge_folder' => ['key' => 'SPECS'],
            ]]]);
        $this->client->expects($this->once())->method('uploadFile')
            ->with('spec.pdf', 'new version', 'SPECS', 'vectorize', 'Specs/spec.pdf', '42', 'e2', true, true, 'oauth.access.token')
            ->willReturn([]);

        $this->runJob(['uid' => 'ada', 'id' => '42']);
    }

    public function testAPersonWithoutACredentialIsSkipped(): void
    {
        $this->userAccounts->method('credentialFor')->willReturn('');
        $this->cloudState->expects($this->never())->method('status');
        $this->client->expects($this->never())->method('uploadFile');

        $this->runJob(['uid' => 'bob', 'id' => '42']);
    }

    /**
     * @param array<string, string> $argument
     */
    private function runJob(array $argument): void
    {
        (new \ReflectionMethod(ReuploadJob::class, 'run'))->invoke($this->job, $argument);
    }
}
