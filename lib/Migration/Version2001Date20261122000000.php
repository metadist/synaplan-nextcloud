<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The watch index (the watches of the people here, by target) and the files
 * waiting out their quiet period before they go to a watch, and the files
 * this app wrote for Synaplan (a watch must not get them back).
 */
class Version2001Date20261122000000 extends SimpleMigrationStep
{
    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array<string, mixed> $options
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        if (!$schema->hasTable('synaplan_watches')) {
            $table = $schema->createTable('synaplan_watches');
            $table->addColumn('watch_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('target_kind', Types::STRING, ['notnull' => true, 'length' => 8]);
            $table->addColumn('target_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
            $table->addColumn('since', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('reconciled', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['watch_id']);
            $table->addIndex(['target_id'], 'synaplan_watches_target');
            $table->addIndex(['uid'], 'synaplan_watches_uid');
        }

        if (!$schema->hasTable('synaplan_watch_queue')) {
            $table = $schema->createTable('synaplan_watch_queue');
            $table->addColumn('watch_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('event', Types::STRING, ['notnull' => false, 'length' => 16]);
            $table->addColumn('changed', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('due', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['watch_id', 'file_id']);
            $table->addIndex(['due'], 'synaplan_watch_queue_due');
        }

        if (!$schema->hasTable('synaplan_own_writes')) {
            $table = $schema->createTable('synaplan_own_writes');
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('written', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['file_id']);
            $table->addIndex(['written'], 'synaplan_own_writes_written');
        }

        return $schema;
    }
}
