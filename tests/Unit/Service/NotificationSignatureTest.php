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
                $vector['token'],
                $fixture['issuer'],
                $fixture['receiver_instance_id'],
                $keys,
                $fixture['now'],
            );
            self::assertSame($vector['expect'], $reason, $vector['name']);
        }
    }

    public function testUnsignedIsRejected(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/notify-vectors.json'), true);
        self::assertIsArray($fixture);
        [$header, $payload] = explode('.', $fixture['vectors'][0]['token']);

        $reason = NotificationSignature::verify(
            $header . '.' . $payload . '.',
            $fixture['issuer'],
            $fixture['receiver_instance_id'],
            [['publicKey' => $fixture['public_key']]],
            $fixture['now'],
        );

        self::assertSame(NotificationSignature::BAD_SIGNATURE, $reason);
    }

    public function testAnUnknownIssuerIsRejected(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/notify-vectors.json'), true);
        self::assertIsArray($fixture);

        $reason = NotificationSignature::verify(
            $fixture['vectors'][0]['token'],
            '',
            $fixture['receiver_instance_id'],
            [['publicKey' => $fixture['public_key']]],
            $fixture['now'],
        );

        self::assertSame(NotificationSignature::WRONG_ISSUER, $reason);
    }

    public function testATokenExpiresAtExp(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/notify-vectors.json'), true);
        self::assertIsArray($fixture);
        $token = $fixture['vectors'][0]['token'];
        $exp = NotificationSignature::claims($token)['exp'];
        $keys = [['publicKey' => $fixture['public_key']]];

        self::assertSame(NotificationSignature::OK, NotificationSignature::verify($token, $fixture['issuer'], $fixture['receiver_instance_id'], $keys, $exp - 1));
        self::assertSame(NotificationSignature::STALE, NotificationSignature::verify($token, $fixture['issuer'], $fixture['receiver_instance_id'], $keys, $exp));
    }
}
