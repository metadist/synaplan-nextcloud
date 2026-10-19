<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Support;

use OCA\SynaplanIntegration\Service\KeyStorage;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\NullLogger;

/**
 * A real KeyStorage over the in-memory config arrays a test already keeps:
 * user values keyed "uid|key", app values keyed by name.
 */
trait KeyStorageStubs
{
    /**
     * A KeyStorage that reads and writes through a test's IConfig stub, for
     * tests that keep every value there.
     */
    private function keyStorageFrom(IConfig $config): KeyStorage
    {
        $user = $this->createStub(IUserConfig::class);
        $user->method('getValueString')->willReturnCallback(
            fn (string $uid, string $app, string $key, string $default = ''): string => (string) $config->getUserValue($uid, $app, $key, $default),
        );
        $user->method('setValueString')->willReturnCallback(
            function (string $uid, string $app, string $key, string $value) use ($config): bool {
                $config->setUserValue($uid, $app, $key, $value);

                return true;
            },
        );
        $user->method('isSensitive')->willReturn(true);

        $app = $this->createStub(IAppConfig::class);
        $app->method('getValueString')->willReturnCallback(
            fn (string $appId, string $key, string $default = ''): string => (string) $config->getAppValue($appId, $key, $default),
        );
        $app->method('setValueString')->willReturnCallback(
            function (string $appId, string $key, string $value) use ($config): bool {
                $config->setAppValue($appId, $key, $value);

                return true;
            },
        );
        $app->method('isSensitive')->willReturn(true);

        return new KeyStorage($user, $app, new NullLogger());
    }

    /**
     * @param array<string, string> $userValues
     * @param array<string, string> $appValues
     * @param array<string, bool>   $sensitive  keys written or marked sensitive, "uid|key" or the app key
     */
    private function keyStorageOver(array &$userValues, array &$appValues, array &$sensitive = []): KeyStorage
    {
        $user = $this->createStub(IUserConfig::class);
        $user->method('getValueString')->willReturnCallback(
            function (string $uid, string $app, string $key, string $default = '') use (&$userValues): string {
                return $userValues[$uid . '|' . $key] ?? $default;
            },
        );
        $user->method('setValueString')->willReturnCallback(
            function (string $uid, string $app, string $key, string $value, bool $lazy = false, int $flags = 0) use (&$userValues, &$sensitive): bool {
                $userValues[$uid . '|' . $key] = $value;
                if (($flags & IUserConfig::FLAG_SENSITIVE) !== 0) {
                    $sensitive[$uid . '|' . $key] = true;
                }

                return true;
            },
        );
        $user->method('isSensitive')->willReturnCallback(
            function (string $uid, string $app, string $key) use (&$sensitive): bool {
                return $sensitive[$uid . '|' . $key] ?? false;
            },
        );
        $user->method('updateSensitive')->willReturnCallback(
            function (string $uid, string $app, string $key, bool $value) use (&$sensitive): bool {
                $sensitive[$uid . '|' . $key] = $value;

                return true;
            },
        );
        $user->method('deleteUserConfig')->willReturnCallback(
            function (string $uid, string $app, string $key) use (&$userValues, &$sensitive): void {
                unset($userValues[$uid . '|' . $key], $sensitive[$uid . '|' . $key]);
            },
        );

        $app = $this->createStub(IAppConfig::class);
        $app->method('getValueString')->willReturnCallback(
            function (string $appId, string $key, string $default = '') use (&$appValues): string {
                return $appValues[$key] ?? $default;
            },
        );
        $app->method('setValueString')->willReturnCallback(
            function (string $appId, string $key, string $value, bool $lazy = false, bool $isSensitive = false) use (&$appValues, &$sensitive): bool {
                $appValues[$key] = $value;
                if ($isSensitive) {
                    $sensitive[$key] = true;
                }

                return true;
            },
        );
        $app->method('isSensitive')->willReturnCallback(
            function (string $appId, string $key) use (&$sensitive): bool {
                return $sensitive[$key] ?? false;
            },
        );
        $app->method('updateSensitive')->willReturnCallback(
            function (string $appId, string $key, bool $value) use (&$sensitive): bool {
                $sensitive[$key] = $value;

                return true;
            },
        );
        $app->method('deleteKey')->willReturnCallback(
            function (string $appId, string $key) use (&$appValues, &$sensitive): void {
                unset($appValues[$key], $sensitive[$key]);
            },
        );

        return new KeyStorage($user, $app, new NullLogger());
    }
}
