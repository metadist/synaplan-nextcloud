<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\PlatformLinkService;
use OCA\SynaplanIntegration\Service\ReadScope;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class ReadScopeTest extends TestCase
{
    /** @var array<string, string> */
    private array $stored = [];

    public function testNothingAllowedMeansNothingMayBeRead(): void
    {
        $scope = $this->scope();

        self::assertFalse($scope->mayRead('ada', 'Invoices'));
        self::assertSame(ReadScope::MODE_WATCHED, $scope->get('ada')['mode']);
    }

    public function testANamedFolderAndWhatIsInsideItMayBeRead(): void
    {
        $scope = $this->scope();
        self::assertNull($scope->allow('ada', '3', 'Invoices'));

        self::assertTrue($scope->mayRead('ada', 'Invoices'));
        self::assertTrue($scope->mayRead('ada', 'Invoices/2026'));
        self::assertFalse($scope->mayRead('ada', 'HR'));
        self::assertSame(ReadScope::MODE_ALLOWED, $scope->get('ada')['mode']);
    }

    public function testTheAdministratorsCapStopsAnotherFolder(): void
    {
        $scope = $this->scope(1);
        self::assertNull($scope->allow('ada', '3', 'Invoices'));

        self::assertSame('cap', $scope->allow('ada', '4', 'HR'));
        self::assertCount(1, $scope->get('ada')['folders']);
    }

    public function testAllFilesMayBeRead(): void
    {
        $scope = $this->scope();
        $scope->save('ada', ReadScope::MODE_ALL, []);

        self::assertTrue($scope->mayRead('ada', 'HR/Contracts'));
    }

    private function scope(int $cap = 20): ReadScope
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn((string) $cap);
        $config->method('getUserValue')->willReturnCallback(
            fn (string $uid, string $app, string $key): string => $this->stored[$uid . $key] ?? '',
        );
        $config->method('setUserValue')->willReturnCallback(
            function (string $uid, string $app, string $key, string $value): void {
                self::assertSame(Application::APP_ID, $app);
                $this->stored[$uid . $key] = $value;
            },
        );
        $links = $this->createMock(PlatformLinkService::class);
        $links->expects($this->any())->method('reportReadScope');

        return new ReadScope($config, $links);
    }
}
