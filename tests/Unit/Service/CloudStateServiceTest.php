<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\CloudStateService;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
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

    protected function setUp(): void
    {
        $this->clients = $this->createMock(IClientService::class);
        $this->http = $this->createMock(IClient::class);
        $this->config = $this->createMock(SynaplanConfig::class);
        $this->client = $this->createMock(SynaplanClient::class);

        $this->clients->method('newClient')->willReturn($this->http);
        $this->config->method('getBaseUrl')->willReturn('http://backend');
        $this->config->method('isPerUserAccountsEnabled')->willReturn(true);
        $this->client->method('getApiKey')->willReturn('sk_ada');
    }

    private function service(): CloudStateService
    {
        return new CloudStateService($this->clients, $this->config, $this->client, new NullLogger());
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
}
