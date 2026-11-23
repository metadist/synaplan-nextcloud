<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Where a watch's reconcile pass stopped at its deadline, so the next run
 * goes on from there instead of asking about the same files again.
 */
class Version2001Date20261123230000 extends SimpleMigrationStep
{
    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array<string, mixed> $options
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        if (!$schema->hasTable('synaplan_watches')) {
            return null;
        }
        $table = $schema->getTable('synaplan_watches');
        if ($table->hasColumn('reconcile_after')) {
            return null;
        }
        $table->addColumn('reconcile_after', Types::BIGINT, ['notnull' => false, 'default' => 0]);

        return $schema;
    }
}
