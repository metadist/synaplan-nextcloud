<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\NotifyKeyCache;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

class NotifyKeyCacheTest extends TestCase
{
    /** @var array<string, string> what the database holds */
    private array $app = [];

    /** @var array<string, string>|null what this request's config cache holds, null before it loads */
    private ?array $seen = null;

    /**
     * @param array{issuer: string, keys: list<array{publicKey: string, kid: string}>} $trust
     */
    private function cache(array $trust, ?PlatformLinkService $links = null, ?ILockingProvider $locks = null): NotifyKeyCache
    {
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            function (string $app, string $key, string $default = ''): string {
                $this->seen ??= $this->app;

                return $this->seen[$key] ?? $default;
            }
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value): void {
                $this->seen ??= $this->app;
                $this->app[$key] = $value;
                $this->seen[$key] = $value;
            }
        );
        $appConfig = $this->createStub(IAppConfig::class);
        $appConfig->method('clearCache')->willReturnCallback(function (): void {
            $this->seen = null;
        });
        if ($links === null) {
            $links = $this->createStub(PlatformLinkService::class);
            $links->method('notificationTrust')->willReturn($trust);
        }

        return new NotifyKeyCache($config, $appConfig, $links, $locks ?? $this->createStub(ILockingProvider::class));
    }

    public function testRefreshKeepsTheIssuerAndKeys(): void
    {
        $this->app['link_instance_id'] = 'pi_nc';
        $cache = $this->cache(['issuer' => 'https://synaplan.example', 'keys' => [['publicKey' => 'ed25519:abc', 'kid' => 'k1']]]);

        $cache->refresh();

        $this->assertSame('https://synaplan.example', $cache->issuer());
        $this->assertSame([['publicKey' => 'ed25519:abc', 'kid' => 'k1']], $cache->keys());
    }

    public function testAnAnswerWithoutKeysKeepsTheLastGoodOnes(): void
    {
        $this->app['link_instance_id'] = 'pi_nc';
        $this->app['notify_issuer'] = 'https://synaplan.example';
        $this->app['notify_keys'] = '[{"publicKey":"ed25519:abc","kid":"k1"}]';
        $cache = $this->cache(['issuer' => 'https://synaplan.example', 'keys' => []]);

        $cache->refresh();

        $this->assertSame([['publicKey' => 'ed25519:abc', 'kid' => 'k1']], $cache->keys());
    }

    public function testAnUnknownKeyRefreshesAtMostOnceAMinute(): void
    {
        $this->app['link_instance_id'] = 'pi_nc';
        $this->app['notify_issuer'] = 'https://synaplan.example';
        $this->app['notify_keys'] = '[{"publicKey":"ed25519:old","kid":"k1"}]';
        $cache = $this->cache(['issuer' => 'https://synaplan.example', 'keys' => [['publicKey' => 'ed25519:new', 'kid' => 'k2']]]);

        $this->assertTrue($cache->refreshForUnknownKey(1000));
        $this->assertSame([['publicKey' => 'ed25519:new', 'kid' => 'k2']], $cache->keys());

        $this->app['notify_keys'] = '[{"publicKey":"ed25519:old","kid":"k1"}]';
        $this->seen = null;
        $this->assertFalse($cache->refreshForUnknownKey(1059));
        $this->assertSame([['publicKey' => 'ed25519:old', 'kid' => 'k1']], $cache->keys());

        $this->assertTrue($cache->refreshForUnknownKey(1060));
    }

    public function testUnchangedKeysAreReported(): void
    {
        $this->app['link_instance_id'] = 'pi_nc';
        $this->app['notify_keys'] = '[{"publicKey":"ed25519:abc","kid":"k1"}]';
        $cache = $this->cache(['issuer' => 'https://synaplan.example', 'keys' => [['publicKey' => 'ed25519:abc', 'kid' => 'k1']]]);

        $this->assertFalse($cache->refreshForUnknownKey(1000));
    }

    public function testARefreshAnotherRequestJustMadeIsUsedInsteadOfRepeated(): void
    {
        $this->app['link_instance_id'] = 'pi_nc';
        $this->app['notify_keys'] = '[{"publicKey":"ed25519:old","kid":"k1"}]';
        $links = $this->createMock(PlatformLinkService::class);
        $links->expects($this->never())->method('notificationTrust');
        $locks = $this->createMock(ILockingProvider::class);
        // While this request waits for the lock, the one holding it refreshes.
        $locks->expects($this->once())->method('acquireLock')->willReturnCallback(function (): void {
            $this->app['notify_keys_unknown_checked_at'] = '1000';
            $this->app['notify_keys'] = '[{"publicKey":"ed25519:new","kid":"k2"}]';
        });
        $locks->expects($this->once())->method('releaseLock')
            ->with('synaplan_integration/notify-keys-unknown', ILockingProvider::LOCK_EXCLUSIVE);
        $cache = $this->cache(['issuer' => '', 'keys' => []], $links, $locks);

        $this->assertTrue($cache->refreshForUnknownKey(1010));
        $this->assertSame([['publicKey' => 'ed25519:new', 'kid' => 'k2']], $cache->keys());
    }
}
