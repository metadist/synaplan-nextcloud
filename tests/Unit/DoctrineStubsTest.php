<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit;

use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * CI also runs the unit tests against the newest API of each Nextcloud
 * version, whose query builder can name a Doctrine constant the stubs lack.
 */
class DoctrineStubsTest extends TestCase
{
    public function testEveryQueryBuilderConstantResolvesAgainstTheStubs(): void
    {
        $constants = (new \ReflectionClass(IQueryBuilder::class))->getConstants();

        self::assertNotEmpty($constants);
        foreach ($constants as $name => $value) {
            self::assertTrue(is_int($value) || is_string($value), $name);
        }
    }
}
