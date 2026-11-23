<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use OCA\SynaplanIntegration\Migration\Version2001Date20261123140000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class SourceEventsTableTest extends TestCase
{
    public function testTheMigrationCreatesTheQueueOrderedPerPerson(): void
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
        $table->expects(self::once())->method('setPrimaryKey')->with(['seq']);
        $table->expects(self::once())->method('addIndex')->with(['uid', 'ord', 'seq'], 'synaplan_source_ev_order');
        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->willReturn(false);
        $schema->expects(self::once())->method('createTable')->with('synaplan_source_events')->willReturn($table);

        (new Version2001Date20261123140000())->changeSchema(
            $this->createMock(IOutput::class),
            static fn () => $schema,
            [],
        );

        self::assertSame([
            'seq' => 'bigint',
            'ord' => 'bigint',
            'uid' => 'string',
            'file_id' => 'bigint',
            'event' => 'string',
            'version' => 'string',
            'path' => 'string',
            'step' => 'string',
            'walked' => 'bigint',
            'due' => 'bigint',
            'attempts' => 'integer',
        ], $columns);
    }

    public function testATableThatIsThereAlreadyIsLeftAlone(): void
    {
        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->willReturn(true);
        $schema->expects(self::never())->method('createTable');

        (new Version2001Date20261123140000())->changeSchema(
            $this->createMock(IOutput::class),
            static fn () => $schema,
            [],
        );
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
