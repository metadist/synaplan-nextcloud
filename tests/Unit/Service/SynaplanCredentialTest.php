<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\SynaplanCredential;
use PHPUnit\Framework\TestCase;

class SynaplanCredentialTest extends TestCase
{
    public function testAnApiKeyTravelsAsXApiKey(): void
    {
        $this->assertSame(['X-API-Key' => 'sk_abc123'], SynaplanCredential::header('sk_abc123'));
    }

    public function testAnAccessTokenTravelsAsBearer(): void
    {
        $this->assertSame(['Authorization' => 'Bearer eyJhbGciOi.x.y'], SynaplanCredential::header('eyJhbGciOi.x.y'));
    }

    public function testOnlyTheSkPrefixMakesAnApiKey(): void
    {
        $this->assertSame(['Authorization' => 'Bearer ask_abc'], SynaplanCredential::header('ask_abc'));
        $this->assertSame(['Authorization' => 'Bearer SK_abc'], SynaplanCredential::header('SK_abc'));
    }
}
