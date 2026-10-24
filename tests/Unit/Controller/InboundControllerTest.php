<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Controller;

use OCA\SynaplanIntegration\Controller\InboundController;
use OCA\SynaplanIntegration\Service\CopyDelivery;
use OCA\SynaplanIntegration\Service\FolderRead;
use OCA\SynaplanIntegration\Service\NotificationSignature;
use OCA\SynaplanIntegration\Service\NotifyKeyCache;
use OCA\SynaplanIntegration\Service\SeenNotificationStore;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Service\UserAccountService;
use OCA\SynaplanIntegration\Tests\Unit\Support\ArrayCacheFactory;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InboundControllerTest extends TestCase
{
    private const NOW = 1700000000;

    private NotifyKeyCache&MockObject $keys;
    private CopyDelivery&MockObject $copies;
    private FolderRead&MockObject $reads;
    private UserAccountService&MockObject $accounts;
    private InboundController $controller;

    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/notify-vectors.json'), true);
        self::assertIsArray($fixture);
        $this->fixture = $fixture;

        $config = $this->createMock(SynaplanConfig::class);
        $config->method('getLinkInstanceId')->willReturn($fixture['receiver_instance_id']);

        $this->keys = $this->createMock(NotifyKeyCache::class);
        $this->keys->method('keys')->willReturn([['publicKey' => $fixture['public_key']]]);
        $this->copies = $this->createMock(CopyDelivery::class);
        $this->reads = $this->createMock(FolderRead::class);
        $this->accounts = $this->createMock(UserAccountService::class);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $this->controller = new InboundController(
            $this->createMock(IRequest::class),
            $config,
            $this->keys,
            new MemorySeenNotifications(),
            $this->copies,
            $this->reads,
            $this->accounts,
            new ArrayCacheFactory(),
            $time,
            new NullLogger(),
        );
    }

    public function testAValidDeliveryIsClaimedOnce(): void
    {
        $this->copies->expects($this->once())->method('handle')->with('9')->willReturn([
            'status' => 200,
            'body' => ['status' => 'delivered', 'remote_path' => '/Synaplan/Documents/plan.docx'],
        ]);
        $this->keys->expects($this->never())->method('refresh');

        $response = $this->controller->receive($this->json('valid'));

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame('delivered', $response->getData()['status']);
    }

    public function testAReplayIsRefusedAndNotClaimedAgain(): void
    {
        $this->copies->expects($this->once())->method('handle')->willReturn([
            'status' => 200,
            'body' => ['status' => 'delivered'],
        ]);

        $this->controller->receive($this->json('valid'));
        $again = $this->controller->receive($this->json('valid'));

        self::assertSame(Http::STATUS_UNAUTHORIZED, $again->getStatus());
        self::assertSame(NotificationSignature::REPLAY, $again->getData()['error']);
    }

    public function testWrongKeyWrongInstanceAndStaleDoNotCallOut(): void
    {
        $this->copies->expects($this->never())->method('handle');
        $this->keys->expects($this->never())->method('refresh');

        foreach (['wrong_key', 'wrong_instance', 'stale'] as $name) {
            $response = $this->controller->receive($this->json($name));
            self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus(), $name);
        }
    }

    public function testUnsignedDoesNotCallOut(): void
    {
        $this->copies->expects($this->never())->method('handle');

        $response = $this->controller->receive('{"type":"delivery","id":"9"}');

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testIdentityRevokedDeletesTheStoredKey(): void
    {
        $this->accounts->expects($this->once())->method('revokeLinkedKey')->with('2267');
        $this->copies->expects($this->never())->method('handle');

        $body = $this->signed([
            'type' => 'identity_revoked',
            'id' => '2267',
            'instance_id' => 'pi_abc',
            'ts' => self::NOW,
            'kid' => 'test',
        ]);
        $response = $this->controller->receive($body);

        self::assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
    }

    public function testPickupIsReadAndAccepted(): void
    {
        $this->copies->expects($this->never())->method('handle');
        $this->reads->expects($this->once())->method('handle')->with('15');

        $body = $this->signed([
            'type' => 'pickup',
            'id' => '15',
            'instance_id' => 'pi_abc',
            'ts' => self::NOW,
            'kid' => 'test',
        ]);
        $response = $this->controller->receive($body);

        self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
    }

    private function json(string $name): string
    {
        foreach ($this->fixture['vectors'] as $vector) {
            if ($vector['name'] === $name) {
                return (string) json_encode($vector['body']);
            }
        }
        self::fail('Missing vector ' . $name);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function signed(array $body): string
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        $encoded = 'ed25519:' . rtrim(strtr(base64_encode($public), '+/', '-_'), '=');
        $this->keys = $this->createMock(NotifyKeyCache::class);
        $this->keys->method('keys')->willReturn([['publicKey' => $encoded]]);

        $signature = sodium_crypto_sign_detached(NotificationSignature::canonical($body), $secret);
        $body['sig'] = 'ed25519:' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        $config = $this->createMock(SynaplanConfig::class);
        $config->method('getLinkInstanceId')->willReturn('pi_abc');
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        $this->controller = new InboundController(
            $this->createMock(IRequest::class),
            $config,
            $this->keys,
            new MemorySeenNotifications(),
            $this->copies,
            $this->reads,
            $this->accounts,
            new ArrayCacheFactory(),
            $time,
            new NullLogger(),
        );

        return (string) json_encode($body);
    }
}

/**
 * The same ten-minute memory the database store provides, without a database.
 */
final class MemorySeenNotifications implements SeenNotificationStore
{
    /** @var array<string, true> */
    private array $seen = [];

    public function alreadySeen(string $type, string $id): bool
    {
        $key = $type . '|' . $id;
        if (isset($this->seen[$key])) {
            return true;
        }
        $this->seen[$key] = true;

        return false;
    }
}
