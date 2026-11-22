<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\WatchIndex;
use OCA\SynaplanIntegration\Service\WatchSync;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WatchSyncTest extends TestCase
{
    private const NOW = 1700000000;

    private WatchIndex&MockObject $index;
    private PlatformLinkService&MockObject $links;
    private IConfig&MockObject $config;
    /** @var array<string, string> */
    private array $app = [];
    /** @var array<string, list<string>> */
    private array $usersWith = [];

    protected function setUp(): void
    {
        $this->index = $this->createMock(WatchIndex::class);
        $this->links = $this->createMock(PlatformLinkService::class);
        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $this->app[$key] ?? $default,
        );
        $this->config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value): void {
                $this->app[$key] = $value;
            },
        );
        $this->config->method('getUsersForUserValue')->willReturnCallback(
            fn (string $app, string $key): array => $this->usersWith[$key] ?? [],
        );
    }

    public function testANoticeMarksThePersonForTheNextRun(): void
    {
        $this->config->expects($this->once())->method('setUserValue')
            ->with('ada', Application::APP_ID, WatchSync::PENDING, '1');

        $this->sync()->request('ada');
    }

    public function testThePersonsListReplacesTheirRows(): void
    {
        $listed = [['id' => 5, 'target_kind' => 'folder', 'target_id' => '30', 'state' => 'active']];
        $this->links->method('canActFor')->willReturn(true);
        $this->links->method('listWatches')->with('ada')->willReturn($listed);
        $this->index->expects($this->once())->method('replace')->with('ada', $listed, self::NOW);

        self::assertTrue($this->sync()->person('ada', self::NOW));
    }

    public function testSomeoneWithoutACredentialHasNoWatchesHere(): void
    {
        $this->links->method('canActFor')->willReturn(false);
        $this->links->expects($this->never())->method('listWatches');
        $this->index->expects($this->once())->method('forget')->with('bob');

        self::assertTrue($this->sync()->person('bob', self::NOW));
    }

    public function testAFailedListKeepsTheRowsAndIsTriedAgain(): void
    {
        $this->links->method('canActFor')->willReturn(true);
        $this->links->method('listWatches')->willThrowException(new \RuntimeException('down'));
        $this->index->expects($this->never())->method('replace');

        self::assertFalse($this->sync()->person('ada', self::NOW));
    }

    public function testARunSyncsMarkedPeopleEveryTimeAndEveryoneEveryTenMinutesAndDaily(): void
    {
        $this->usersWith = [WatchSync::PENDING => ['ada'], 'ai_consent' => ['carol', 'dave']];
        $this->app = ['watch_sync_round' => (string) (self::NOW - 60), 'watch_sync_full' => (string) (self::NOW - 60)];
        $this->index->method('uids')->willReturn(['bob']);
        $this->links->method('canActFor')->willReturnCallback(static fn (string $uid): bool => $uid !== 'dave');
        $synced = [];
        $this->links->method('listWatches')->willReturnCallback(function (string $uid) use (&$synced): array {
            $synced[] = $uid;

            return [];
        });

        $this->sync()->run(self::NOW);
        self::assertSame(['ada'], $synced);

        $synced = [];
        $this->sync()->run(self::NOW + 600);
        self::assertSame(['ada', 'bob'], $synced);

        $synced = [];
        $this->sync()->run(self::NOW + 86400);
        self::assertSame(['ada', 'bob', 'carol'], $synced);
    }

    private function sync(): WatchSync
    {
        return new WatchSync($this->index, $this->links, $this->config, new NullLogger());
    }
}
