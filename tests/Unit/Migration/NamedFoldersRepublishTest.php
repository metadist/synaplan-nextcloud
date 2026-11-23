<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Migration;

use OCA\SynaplanIntegration\Migration\Version2001Date20261123130000;
use OCA\SynaplanIntegration\Service\ReadScope;
use OCP\Config\IUserConfig;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class NamedFoldersRepublishTest extends TestCase
{
    /** @var array<string, string> uid . key => value */
    private array $userValues = [];

    public function testWhoeverKeptNamedFoldersUnderAnotherChoiceIsReportedAgain(): void
    {
        $settings = [
            'ada' => '{"mode":"watched","folders":[{"id":"3","path":"HR"}]}',
            'bob' => '{"mode":"all","folders":[{"id":"4","path":"Finance"}]}',
            'carol' => '{"mode":"allowed","folders":[{"id":"5","path":"Specs"}]}',
            'dave' => '{"mode":"watched","folders":[]}',
            'erin' => 'not json',
        ];

        $this->migrate($settings);

        self::assertSame(
            ['ada' . ReadScope::PENDING => '1', 'bob' . ReadScope::PENDING => '1'],
            $this->userValues,
        );
    }

    /**
     * @param array<string, string> $settings uid => saved read setting
     */
    private function migrate(array $settings): void
    {
        $config = $this->createStub(IConfig::class);
        $config->method('setUserValue')->willReturnCallback(
            function (string $uid, string $app, string $key, string $value): void {
                $this->userValues[$uid . $key] = $value;
            },
        );
        $userConfig = $this->createStub(IUserConfig::class);
        $userConfig->method('getValuesByUsers')->willReturn($settings);

        (new Version2001Date20261123130000($config, $userConfig))->postSchemaChange(
            $this->createStub(IOutput::class),
            fn (): ISchemaWrapper => $this->createStub(ISchemaWrapper::class),
            [],
        );
    }
}
