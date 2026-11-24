<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Each person's source events for Synaplan, sent in the order they happened:
 * `ord` is when, `seq` breaks ties, and a row that waits for a retry holds
 * back the rows after it.
 */
class Version2001Date20261123140000 extends SimpleMigrationStep
{
    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array<string, mixed> $options
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        if (!$schema->hasTable('synaplan_source_events')) {
            $table = $schema->createTable('synaplan_source_events');
            $table->addColumn('seq', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('ord', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('event', Types::STRING, ['notnull' => true, 'length' => 16]);
            $table->addColumn('version', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('path', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('step', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'send']);
            $table->addColumn('walked', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('due', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['seq']);
            $table->addIndex(['uid', 'ord', 'seq'], 'synaplan_source_ev_order');
            $table->addIndex(['step', 'ord'], 'synaplan_source_ev_step');
        }

        return $schema;
    }
}
