<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\CloudStateService;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCA\SynaplanIntegration\Tests\Unit\Support\ArrayCache;
use OCA\SynaplanIntegration\Tests\Unit\Support\ArrayCacheFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CloudStateServiceTest extends TestCase
{
    private IClientService&MockObject $clients;
    private IClient&MockObject $http;
    private SynaplanConfig&MockObject $config;
    private SynaplanClient&MockObject $client;
    private ArrayCache $cache;

    protected function setUp(): void
    {
        $this->clients = $this->createMock(IClientService::class);
        $this->http = $this->createMock(IClient::class);
        $this->config = $this->createMock(SynaplanConfig::class);
        $this->client = $this->createMock(SynaplanClient::class);
        $this->cache = new ArrayCache();

        $this->clients->method('newClient')->willReturn($this->http);
        $this->config->method('getBaseUrl')->willReturn('http://backend');
        $this->config->method('isPerUserAccountsEnabled')->willReturn(true);
        $this->client->method('getApiKey')->willReturn('sk_ada');
    }

    private function service(): CloudStateService
    {
        return new CloudStateService($this->clients, $this->config, $this->client, new NullLogger(), new ArrayCacheFactory($this->cache));
    }

    public function testStatusReturnsDisabledWhenFeatureIs404(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(404);
        $response->method('getBody')->willReturn('{"error":"feature_not_configured"}');
        $this->http->method('post')->willReturn($response);

        $result = $this->service()->status([['id' => '42', 'version' => 'e1']]);

        $this->assertFalse($result['enabled']);
        $this->assertSame([], $result['items']);
    }

    public function testStatusReturnsItemsAndWatches(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn((string) json_encode([
            'items' => [['id' => '42', 'state' => 'searchable', 'knowledge_folder' => ['key' => 'Specs', 'name' => 'Specs'], 'file_id' => 9, 'kept_up_to_date' => true]],
            'watches' => [],
        ]));
        $this->http->method('post')->willReturn($response);

        $result = $this->service()->status([['id' => '42', 'version' => 'e1']]);

        $this->assertTrue($result['enabled']);
        $this->assertSame('searchable', $result['items'][0]['state']);
    }

    public function testTheGlyphsSynaplanSetsAreKeptForFiveMinutes(): void
    {
        $this->http->expects($this->once())->method('get')
            ->with('http://backend/api/v1/config/runtime', $this->callback(
                static fn (array $options): bool => !isset($options['headers']['Authorization']),
            ))
            ->willReturn($this->answer(200, (string) json_encode([
                'cloud_files' => ['glyphs' => ['bird' => 'https://synaplan.example.org/bird.svg', 'bird_eyes' => '']],
            ])));

        $first = $this->service()->glyphOverrides();
        $second = $this->service()->glyphOverrides();

        self::assertSame(['bird' => 'https://synaplan.example.org/bird.svg', 'bird_eyes' => null], $first);
        self::assertSame($first, $second);
        self::assertSame([300], array_values($this->cache->ttls));
    }

    public function testNoGlyphsAreShownInsteadOfTheBirdWhenSynaplanSetsNone(): void
    {
        $this->http->method('get')->willReturn($this->answer(200, '{"cloud_files":[]}'));

        self::assertSame(['bird' => null, 'bird_eyes' => null], $this->service()->glyphOverrides());
        self::assertSame([300], array_values($this->cache->ttls));
    }

    public function testWithoutAnAnswerTheBirdIsShownAndSynaplanIsAskedAgainAfterAMinute(): void
    {
        foreach ([
            'an error' => fn (): IResponse => $this->answer(500, ''),
            'no answer' => static fn (): IResponse => throw new \RuntimeException('Connection refused'),
        ] as $case => $answer) {
            $this->cache = new ArrayCache();
            $http = $this->createMock(IClient::class);
            $http->expects($this->once())->method('get')->willReturnCallback($answer);
            $this->clients = $this->createMock(IClientService::class);
            $this->clients->method('newClient')->willReturn($http);

            $first = $this->service()->glyphOverrides();
            $second = $this->service()->glyphOverrides();

            self::assertSame(['bird' => null, 'bird_eyes' => null], $first, $case);
            self::assertSame($first, $second, $case);
            self::assertSame([60], array_values($this->cache->ttls), $case);
        }
    }

    private function answer(int $status, string $body): IResponse
    {
        $response = $this->createStub(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);

        return $response;
    }
}
