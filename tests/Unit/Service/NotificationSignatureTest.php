<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\NotificationSignature;
use PHPUnit\Framework\TestCase;

final class NotificationSignatureTest extends TestCase
{
    public function testSharedVectors(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/notify-vectors.json'), true);
        self::assertIsArray($fixture);
        $keys = [['publicKey' => $fixture['public_key']]];

        foreach ($fixture['vectors'] as $vector) {
            $reason = NotificationSignature::verify(
                $vector['body'],
                $fixture['receiver_instance_id'],
                $keys,
                $fixture['now'],
                $fixture['window_seconds'],
            );
            self::assertSame($vector['expect'], $reason, $vector['name']);
        }
    }

    public function testUnsignedIsRejected(): void
    {
        $reason = NotificationSignature::verify(
            ['type' => 'delivery', 'id' => '9', 'instance_id' => 'pi_abc', 'ts' => 1700000000],
            'pi_abc',
            [['publicKey' => 'ed25519:4dwtgZJhZVjjcxGFKBtTeZtGdpEFmUcp_rz0xfgPGFM']],
            1700000000,
        );

        self::assertSame(NotificationSignature::BAD_SIGNATURE, $reason);
    }
}
