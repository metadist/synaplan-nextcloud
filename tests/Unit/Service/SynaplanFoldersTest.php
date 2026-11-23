<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Service;

use OCA\SynaplanIntegration\Service\SynaplanFolders;
use PHPUnit\Framework\TestCase;

final class SynaplanFoldersTest extends TestCase
{
    public function testAFolderIsCleanedOrRefused(): void
    {
        self::assertSame('', SynaplanFolders::cleanFolder(''));
        self::assertSame('', SynaplanFolders::cleanFolder('   '));
        self::assertSame('', SynaplanFolders::cleanFolder(' / '));
        self::assertSame('Projects/Q3', SynaplanFolders::cleanFolder(' /Projects/Q3/ '));
        self::assertSame('Projects/Q3', SynaplanFolders::cleanFolder('Projects\\Q3'));
        foreach (['Projects/ /Q3', 'Projects//Q3', 'Projects/../HR', './Q3'] as $refused) {
            try {
                SynaplanFolders::cleanFolder($refused);
                self::fail($refused . ' was accepted');
            } catch (\InvalidArgumentException) {
            }
        }
    }
}
