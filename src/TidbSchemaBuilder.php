<?php

namespace StringKe\TidbPhp\Laravel;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;

/**
 * @property TidbConnection $connection
 * @property TidbSchemaGrammar $grammar
 */
class TidbSchemaBuilder extends Builder
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection);

        $this->blueprintResolver(fn (Connection $connection, string $table, ?Closure $callback): TidbBlueprint => new TidbBlueprint($connection, $table, $callback));
    }

    public function dropAllTables()
    {
        $tables = $this->getTableListing($this->getCurrentSchemaListing());

        if ($tables !== []) {
            $this->disableForeignKeyConstraints();

            try {
                $this->connection->statement($this->grammar->compileDropAllTables($tables));
            } finally {
                $this->enableForeignKeyConstraints();
            }
        }

        $this->dropAllSequences();
    }

    public function dropAllViews()
    {
        $views = array_column($this->getViews($this->getCurrentSchemaListing()), 'schema_qualified_name');

        if ($views !== []) {
            $this->connection->statement($this->grammar->compileDropAllViews($views));
        }
    }

    /**
     * @param  string|string[]|null  $schema
     * @return list<array{name: string, schema: string, schema_qualified_name: string}>
     */
    public function getSequences($schema = null): array
    {
        return array_values(array_map(function (object $row): array {
            ['name' => $name, 'schema' => $schema] = array_map(strval(...), (array) $row);

            return ['name' => $name, 'schema' => $schema, 'schema_qualified_name' => $schema.'.'.$name];
        }, $this->connection->selectFromWriteConnection($this->grammar->compileSequences($schema))));
    }

    public function dropAllSequences(): void
    {
        $sequences = array_column($this->getSequences($this->getCurrentSchemaListing()), 'schema_qualified_name');

        if ($sequences !== []) {
            $this->connection->statement($this->grammar->compileDropAllSequences($sequences));
        }
    }

    public function getCurrentSchemaListing()
    {
        return [$this->connection->getDatabaseName()];
    }
}
