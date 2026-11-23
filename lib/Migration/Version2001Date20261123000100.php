<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A fresh token for each change queued for a watch, so the feed settles only
 * the change it sent, even when a newer one has the same second and event.
 */
class Version2001Date20261123000100 extends SimpleMigrationStep
{
    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array<string, mixed> $options
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        if (!$schema->hasTable('synaplan_watch_queue')) {
            return null;
        }
        $table = $schema->getTable('synaplan_watch_queue');
        if ($table->hasColumn('generation')) {
            return null;
        }
        $table->addColumn('generation', Types::STRING, ['notnull' => false, 'length' => 32]);

        return $schema;
    }
}
