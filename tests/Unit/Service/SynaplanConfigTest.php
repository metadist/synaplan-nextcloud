<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SynaplanConfigTest extends TestCase
{
    private IConfig&MockObject $config;
    /** @var array<string, string> */
    private array $appConfig = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(IConfig::class);
        $this->appConfig = [];
        $this->config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => $this->appConfig[$key] ?? $default
        );
    }

    private function cfg(): SynaplanConfig
    {
        return new SynaplanConfig($this->config);
    }

    public function testUnsetModeResolvesToLegacyBehaviour(): void
    {
        $this->assertSame(SynaplanConfig::MODE_SHARED, $this->cfg()->getMode());
        $this->assertFalse($this->cfg()->isPerUserAccountsEnabled());

        $this->appConfig['per_user_accounts'] = '1';
        $this->assertSame(SynaplanConfig::MODE_PROVISION, $this->cfg()->getMode());
        $this->assertTrue($this->cfg()->isPerUserAccountsEnabled());
    }

    public function testExplicitModeWinsOverLegacyFlag(): void
    {
        $this->appConfig['per_user_accounts'] = '1';
        $this->appConfig['mode'] = SynaplanConfig::MODE_SHARED;
        $this->assertSame(SynaplanConfig::MODE_SHARED, $this->cfg()->getMode());
        $this->assertFalse($this->cfg()->isPerUserAccountsEnabled());

        $this->appConfig['per_user_accounts'] = '0';
        $this->appConfig['mode'] = SynaplanConfig::MODE_LINK;
        $this->assertSame(SynaplanConfig::MODE_LINK, $this->cfg()->getMode());
        $this->assertTrue($this->cfg()->isPerUserAccountsEnabled());
    }

    public function testIsLinkAvailableNeedsModeAndRegistration(): void
    {
        $this->appConfig['mode'] = SynaplanConfig::MODE_LINK;
        $this->assertFalse($this->cfg()->isLinkAvailable());

        $this->appConfig['link_instance_id'] = 'pi_abc';
        $this->assertFalse($this->cfg()->isLinkAvailable());

        $this->appConfig['link_instance_secret'] = 'enc-secret';
        $this->assertTrue($this->cfg()->isLinkAvailable());

        $this->appConfig['mode'] = SynaplanConfig::MODE_PROVISION;
        $this->assertFalse($this->cfg()->isLinkAvailable());
    }

    public function testAutoProvisionNeedsLinkModeFlagAndAdminKey(): void
    {
        $this->appConfig['mode'] = SynaplanConfig::MODE_LINK;
        $this->appConfig['link_auto_provision'] = '1';
        $this->assertFalse($this->cfg()->isAutoProvisionEnabled());

        $this->appConfig['api_key'] = 'sk_admin';
        $this->assertTrue($this->cfg()->isAutoProvisionEnabled());

        $this->appConfig['mode'] = SynaplanConfig::MODE_PROVISION;
        $this->assertFalse($this->cfg()->isAutoProvisionEnabled());
    }

    public function testPublicBaseUrlFallsBackToApiUrl(): void
    {
        $this->appConfig['synaplan_url'] = 'http://backend';
        $this->assertSame('http://backend', $this->cfg()->getPublicBaseUrl());

        $this->appConfig['synaplan_public_url'] = 'http://localhost:5173';
        $this->assertSame('http://localhost:5173', $this->cfg()->getPublicBaseUrl());
    }
}
