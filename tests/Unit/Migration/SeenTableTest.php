<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use OCA\SynaplanIntegration\Migration\Version2001Date20261123120000;
use OCA\SynaplanIntegration\Service\SeenNotifications;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class SeenTableTest extends TestCase
{
    public function testTheMigrationCreatesTheTableOfSeenNotifications(): void
    {
        $columns = [];
        // Nextcloud 35 declares its own ITable where 33 returns Doctrine's Table.
        $tableClass = self::declaredReturnType(ISchemaWrapper::class, 'createTable', Table::class);
        $table = $this->createMock($tableClass);
        $column = $this->createMock(self::declaredReturnType($tableClass, 'addColumn', Column::class));
        $table->method('addColumn')->willReturnCallback(
            function (string $name, string $type) use (&$columns, $column) {
                $columns[$name] = $type;

                return $column;
            }
        );
        $table->expects(self::once())->method('setPrimaryKey')->with(['notice_hash']);
        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->willReturn(false);
        $schema->expects(self::once())->method('createTable')->with('synaplan_seen')->willReturn($table);

        (new Version2001Date20261123120000())->changeSchema(
            $this->createMock(IOutput::class),
            static fn () => $schema,
            [],
        );

        self::assertSame(['notice_hash' => 'string', 'expires' => 'bigint'], $columns);
    }

    public function testATableThatIsThereAlreadyIsLeftAlone(): void
    {
        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->willReturn(true);
        $schema->expects(self::never())->method('createTable');

        (new Version2001Date20261123120000())->changeSchema(
            $this->createMock(IOutput::class),
            static fn () => $schema,
            [],
        );
    }

    public function testANotificationRunsNoHandWrittenSql(): void
    {
        $db = $this->createMock(IDBConnection::class);
        $db->method('tableExists')->willReturn(false);
        $db->expects(self::never())->method('executeStatement');
        $db->method('getQueryBuilder')->willThrowException(new \LogicException('query builder reached'));

        $this->expectExceptionMessage('query builder reached');
        (new SeenNotifications($db))->alreadySeen('copy', 'n-1');
    }

    /**
     * @param class-string $class
     * @param class-string $undeclared the type when the method declares none
     *
     * @return class-string
     */
    private static function declaredReturnType(string $class, string $method, string $undeclared): string
    {
        $type = (new \ReflectionMethod($class, $method))->getReturnType();
        if (!$type instanceof \ReflectionNamedType) {
            return $undeclared;
        }

        /** @var class-string */
        return $type->getName();
    }
}
