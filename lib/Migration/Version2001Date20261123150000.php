<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Why Synaplan holds a watch, so a restored target is reported only to a
 * watch that lost it.
 */
class Version2001Date20261123150000 extends SimpleMigrationStep
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
        if ($table->hasColumn('reason')) {
            return null;
        }
        $table->addColumn('reason', Types::STRING, ['notnull' => false, 'length' => 32]);

        return $schema;
    }
}
