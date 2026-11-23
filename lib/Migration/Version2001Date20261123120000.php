<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The notifications already accepted, so a repeat is refused. Servers that
 * accepted one before this step already have the table.
 */
class Version2001Date20261123120000 extends SimpleMigrationStep
{
    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array<string, mixed> $options
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        if (!$schema->hasTable('synaplan_seen')) {
            $table = $schema->createTable('synaplan_seen');
            $table->addColumn('notice_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('expires', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['notice_hash']);
        }

        return $schema;
    }
}
