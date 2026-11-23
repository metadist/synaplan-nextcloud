<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Migration;

use OCA\SynaplanIntegration\Migration\Version2001Date20261123110000;
use OCA\SynaplanIntegration\Service\ReadScope;
use OCP\Config\IUserConfig;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class ReadCeilingSeedTest extends TestCase
{
    /** @var array<string, string> */
    private array $appValues = [];

    public function testAnUpdateWhereSomeoneReadsAllTheirFilesStartsTheCeilingAtAll(): void
    {
        $this->seed([
            'ada' => '{"mode":"watched","folders":[]}',
            'bob' => '{"mode":"all","folders":[]}',
        ]);

        self::assertSame(ReadScope::MODE_ALL, $this->appValues[ReadScope::CEILING] ?? null);
    }

    public function testWithoutAllTheDefaultStays(): void
    {
        $this->seed(['ada' => '{"mode":"allowed","folders":[{"id":"3","path":"HR"}]}']);

        self::assertArrayNotHasKey(ReadScope::CEILING, $this->appValues);
    }

    public function testACeilingTheAdministratorSetIsKept(): void
    {
        $this->appValues[ReadScope::CEILING] = ReadScope::MODE_WATCHED;

        $this->seed(['bob' => '{"mode":"all","folders":[]}']);

        self::assertSame(ReadScope::MODE_WATCHED, $this->appValues[ReadScope::CEILING]);
    }

    /**
     * @param array<string, string> $settings uid => saved read setting
     */
    private function seed(array $settings): void
    {
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $this->appValues[$key] ?? $default,
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value): void {
                $this->appValues[$key] = $value;
            },
        );
        $userConfig = $this->createStub(IUserConfig::class);
        $userConfig->method('getValuesByUsers')->willReturn($settings);

        (new Version2001Date20261123110000($config, $userConfig))->postSchemaChange(
            $this->createStub(IOutput::class),
            fn (): ISchemaWrapper => $this->createStub(ISchemaWrapper::class),
            [],
        );
    }
}
