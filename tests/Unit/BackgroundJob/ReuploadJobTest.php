<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\BackgroundJob;

use OCA\SynaplanIntegration\BackgroundJob\ReuploadJob;
use OCA\SynaplanIntegration\Exception\FailureException;
use OCA\SynaplanIntegration\Service\CloudStateService;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ReuploadJobTest extends TestCase
{
    private UserAccountService&MockObject $userAccounts;
    private CloudStateService&MockObject $cloudState;
    private SynaplanClient&MockObject $client;
    private ReuploadJob $job;
    /** @var list<array{string, int, mixed}> */
    private array $scheduled = [];

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
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(1_000);
        $jobs = $this->createStub(IJobList::class);
        $jobs->method('scheduleAfter')->willReturnCallback(function (string $job, int $runAfter, mixed $argument): void {
            $this->scheduled[] = [$job, $runAfter, $argument];
        });
        $this->job = new ReuploadJob(
            $time,
            $this->userAccounts,
            $root,
            $this->cloudState,
            $this->client,
            new NullLogger(),
            $jobs,
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

    public function testAHeldGrantWhoseTokenCannotBeRenewedNowIsTriedAgainInAMinute(): void
    {
        $this->userAccounts->method('credentialFor')->willReturn('');
        $this->userAccounts->method('holdsGrant')->with('ada')->willReturn(true);
        $this->client->expects($this->never())->method('uploadFile');

        $this->runJob(['uid' => 'ada', 'id' => '42']);

        self::assertSame([[ReuploadJob::class, 1_060, ['uid' => 'ada', 'id' => '42', 'attempt' => 1]]], $this->scheduled);
    }

    public function testAPersonWithoutAGrantIsNotTriedAgain(): void
    {
        $this->userAccounts->method('credentialFor')->willReturn('');
        $this->userAccounts->method('holdsGrant')->willReturn(false);

        $this->runJob(['uid' => 'bob', 'id' => '42']);

        self::assertSame([], $this->scheduled);
    }

    public function testAnUnreachableSynaplanIsAskedAgainLaterEachTime(): void
    {
        $this->userAccounts->method('credentialFor')->willReturn('sk');
        $this->cloudState->method('status')->willReturn(['enabled' => true, 'unreachable' => true, 'items' => [], 'watches' => []]);
        $this->client->expects($this->never())->method('uploadFile');

        $this->runJob(['uid' => 'ada', 'id' => '42', 'attempt' => 2]);

        self::assertSame([[ReuploadJob::class, 1_900, ['uid' => 'ada', 'id' => '42', 'attempt' => 3]]], $this->scheduled);
    }

    /**
     * @return iterable<string, array{\Throwable, bool}>
     */
    public static function uploadFailures(): iterable
    {
        yield 'no answer' => [new \RuntimeException('cURL error 28', 0), true];
        yield 'busy' => [new \RuntimeException('Too Many Requests', 429), true];
        yield 'failing' => [new \RuntimeException('Bad Gateway', 502), true];
        yield 'file refused' => [new \RuntimeException('Payload Too Large', 413), false];
        yield 'key refused' => [FailureException::notConnected(), false];
    }

    #[DataProvider('uploadFailures')]
    public function testAnUploadThatFailsForNowIsTriedAgain(\Throwable $failure, bool $again): void
    {
        $this->keptUpToDate();
        $this->client->method('uploadFile')->willThrowException($failure);

        $this->runJob(['uid' => 'ada', 'id' => '42']);

        self::assertSame($again ? [[ReuploadJob::class, 1_060, ['uid' => 'ada', 'id' => '42', 'attempt' => 1]]] : [], $this->scheduled);
    }

    public function testTheLastRetryIsTheLast(): void
    {
        $this->keptUpToDate();
        $this->client->method('uploadFile')->willThrowException(new \RuntimeException('cURL error 7', 0));

        $this->runJob(['uid' => 'ada', 'id' => '42', 'attempt' => count(ReuploadJob::RETRY_DELAYS)]);

        self::assertSame([], $this->scheduled);
    }

    private function keptUpToDate(): void
    {
        $this->userAccounts->method('credentialFor')->willReturn('sk');
        $this->cloudState->method('status')->willReturn(['enabled' => true, 'items' => [['state' => 'indexed', 'kept_up_to_date' => true]], 'watches' => []]);
    }

    /**
     * @param array<string, string|int> $argument
     */
    private function runJob(array $argument): void
    {
        (new \ReflectionMethod(ReuploadJob::class, 'run'))->invoke($this->job, $argument);
    }
}
