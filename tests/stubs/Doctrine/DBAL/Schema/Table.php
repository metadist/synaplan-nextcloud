<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

/**
 * The part of Doctrine's table that the app's migrations use. Nextcloud
 * ships Doctrine DBAL; nextcloud/ocp does not.
 */
class Table
{
    /**
     * @param array<string, mixed> $options
     */
    public function addColumn(string $name, string $typeName, array $options = []): Column
    {
        return new Column();
    }

    /**
     * @param list<string> $columnNames
     */
    public function setPrimaryKey(array $columnNames, string|false $indexName = false): self
    {
        return $this;
    }

    /**
     * @param list<string> $columnNames
     * @param list<string> $flags
     * @param array<string, mixed> $options
     */
    public function addIndex(array $columnNames, ?string $indexName = null, array $flags = [], array $options = []): self
    {
        return $this;
    }
}
