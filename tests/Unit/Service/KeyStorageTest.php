<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\KeyStorage;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class KeyStorageTest extends TestCase
{
    public function testAPersonsKeyIsWrittenSensitive(): void
    {
        $userConfig = $this->createMock(IUserConfig::class);
        $userConfig->expects($this->once())
            ->method('setValueString')
            ->with('ada', 'synaplan_integration', 'synaplan_user_api_key', 'sk_ada', false, IUserConfig::FLAG_SENSITIVE);

        $this->storage($userConfig)->setUserKey('ada', 'sk_ada');
    }

    public function testASensitiveKeyIsReadAsItIs(): void
    {
        $userConfig = $this->createMock(IUserConfig::class);
        $userConfig->method('getValueString')->willReturn('sk_ada');
        $userConfig->method('isSensitive')->willReturn(true);
        $userConfig->expects($this->never())->method('updateSensitive');

        $this->assertSame('sk_ada', $this->storage($userConfig)->getUserKey('ada'));
    }

    public function testAPlainTextKeyFromAnOlderReleaseIsEncryptedOnFirstRead(): void
    {
        $userConfig = $this->createMock(IUserConfig::class);
        $userConfig->method('getValueString')->willReturn('sk_from_1_6');
        $userConfig->method('isSensitive')->willReturn(false);
        $userConfig->expects($this->once())
            ->method('updateSensitive')
            ->with('ada', 'synaplan_integration', 'synaplan_user_api_key', true);

        $this->assertSame('sk_from_1_6', $this->storage($userConfig)->getUserKey('ada'));
    }

    public function testAKeyThatNoLongerDecryptsIsRemovedAndLoggedWithoutItsValue(): void
    {
        $cipher = '$UserConfigEncryption$3c6e0b8a9c15224a8228b9a98ca1531d|aes-256-cbc';
        $userConfig = $this->createMock(IUserConfig::class);
        $userConfig->method('getValueString')->willReturn($cipher);
        $userConfig->expects($this->once())
            ->method('deleteUserConfig')
            ->with('ada', 'synaplan_integration', 'synaplan_user_api_key');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('could not be decrypted'),
                $this->callback(fn (array $context): bool => !str_contains((string) json_encode($context), 'UserConfigEncryption')),
            );

        $this->assertNull($this->storage($userConfig, logger: $logger)->getUserKey('ada'));
    }

    public function testNoKeyStaysEmpty(): void
    {
        $userConfig = $this->createMock(IUserConfig::class);
        $userConfig->method('getValueString')->willReturn('');
        $userConfig->expects($this->never())->method('isSensitive');

        $this->assertSame('', $this->storage($userConfig)->getUserKey('bob'));
    }

    public function testTheAdminKeyIsWrittenSensitive(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->expects($this->once())
            ->method('setValueString')
            ->with('synaplan_integration', 'api_key', 'sk_admin', false, true);

        $this->storage(appConfig: $appConfig)->setAppKey('api_key', 'sk_admin');
    }

    public function testAPlainTextAdminKeyIsEncryptedOnFirstRead(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('sk_admin');
        $appConfig->method('isSensitive')->willReturn(false);
        $appConfig->expects($this->once())->method('updateSensitive')->with('synaplan_integration', 'api_key_local', true);

        $this->assertSame('sk_admin', $this->storage(appConfig: $appConfig)->getAppKey('api_key_local'));
    }

    public function testAnAdminKeyThatNoLongerDecryptsIsRemoved(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willThrowException(new \Exception('HMAC does not match.'));
        $appConfig->expects($this->once())->method('deleteKey')->with('synaplan_integration', 'api_key');

        $this->assertSame('', $this->storage(appConfig: $appConfig)->getAppKey('api_key'));
    }

    private function storage(?IUserConfig $userConfig = null, ?IAppConfig $appConfig = null, ?LoggerInterface $logger = null): KeyStorage
    {
        return new KeyStorage(
            $userConfig ?? $this->createStub(IUserConfig::class),
            $appConfig ?? $this->createStub(IAppConfig::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
