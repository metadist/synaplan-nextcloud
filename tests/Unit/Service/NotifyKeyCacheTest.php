<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\NotifyKeyCache;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class NotifyKeyCacheTest extends TestCase
{
    /** @var array<string, string> */
    private array $app = [];

    /**
     * @param array{issuer: string, keys: list<array{publicKey: string, kid: string}>} $trust
     */
    private function cache(array $trust): NotifyKeyCache
    {
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $this->app[$key] ?? $default
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value): void {
                $this->app[$key] = $value;
            }
        );
        $links = $this->createStub(PlatformLinkService::class);
        $links->method('notificationTrust')->willReturn($trust);

        return new NotifyKeyCache($config, $links);
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
}
