<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The version of each file this app wrote, so a person's edit of it is told
 * apart from the app's own write.
 */
class Version2001Date20261123000000 extends SimpleMigrationStep
{
    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array<string, mixed> $options
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        if (!$schema->hasTable('synaplan_own_writes')) {
            return null;
        }
        $table = $schema->getTable('synaplan_own_writes');
        if ($table->hasColumn('version')) {
            return null;
        }
        $table->addColumn('version', Types::STRING, ['notnull' => false, 'length' => 255]);

        return $schema;
    }
}
