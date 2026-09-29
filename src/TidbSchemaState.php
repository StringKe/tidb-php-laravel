<?php

namespace StringKe\TidbPhp\Laravel;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\SchemaState;

/**
 * Builds the dump from SHOW CREATE output over the driver connection, so no mysqldump binary or client credentials are involved.
 *
 * @property TidbConnection $connection
 */
class TidbSchemaState extends SchemaState
{
    private const string SEPARATOR = ";\n\n";

    public function dump(Connection $connection, $path)
    {
        /** @var TidbSchemaBuilder $schema */
        $schema = $connection->getSchemaBuilder();

        $statements = ['SET FOREIGN_KEY_CHECKS=0'];

        foreach ($schema->getSequences($connection->getDatabaseName()) as $sequence) {
            $statements[] = $this->showCreate($connection, 'sequence', $sequence['name']);
        }

        foreach ($schema->getTableListing($connection->getDatabaseName(), false) as $table) {
            $statements[] = preg_replace(['/ AUTO_INCREMENT=\d+/', '#\s*/\*T!\[auto_rand_base] AUTO_RANDOM_BASE=\d+ \*/#'], '', $this->showCreate($connection, 'table', $table));
        }

        foreach ($this->orderViews($connection, array_column($schema->getViews($connection->getDatabaseName()), 'name')) as $view) {
            $statements[] = $view;
        }

        $statements[] = 'SET FOREIGN_KEY_CHECKS=1';

        if ($this->hasMigrationTable()) {
            array_push($statements, ...$this->migrationRows($connection));
        }

        $this->files->put($path, implode(self::SEPARATOR, $statements).self::SEPARATOR);
    }

    public function load($path)
    {
        foreach (explode(self::SEPARATOR, $this->files->get($path)) as $statement) {
            if (trim($statement) !== '') {
                $this->connection->executeRaw($statement);
            }
        }
    }

    private function showCreate(Connection $connection, string $type, string $name): string
    {
        $row = array_values((array) $connection->selectOne("show create {$type} ".$connection->getSchemaGrammar()->wrap($name)));

        return (string) $row[1];
    }

    /**
     * @param  list<string>  $views
     * @return list<string>
     */
    private function orderViews(Connection $connection, array $views): array
    {
        $definitions = [];

        foreach ($views as $view) {
            $definitions[$view] = preg_replace('/ DEFINER=`[^`]*`@`[^`]*`/', '', $this->showCreate($connection, 'view', $view));
        }

        $ordered = [];
        $visit = function (string $view, array $path) use (&$visit, &$ordered, $definitions): void {
            if (isset($ordered[$view]) || isset($path[$view])) {
                return;
            }

            foreach (array_keys($definitions) as $other) {
                if ($other !== $view && str_contains($definitions[$view], '`'.str_replace('`', '``', $other).'`')) {
                    $visit($other, $path + [$view => true]);
                }
            }

            $ordered[$view] = $definitions[$view];
        };

        foreach (array_keys($definitions) as $view) {
            $visit($view, []);
        }

        return array_values($ordered);
    }

    /**
     * @return list<string>
     */
    private function migrationRows(Connection $connection): array
    {
        $table = $connection->getSchemaGrammar()->wrapTable($this->migrationTable);

        return array_values($connection->table($this->migrationTable)->orderBy('batch')->orderBy('migration')->get(['migration', 'batch'])
            ->map(fn ($row) => "insert into {$table} (`migration`, `batch`) values ({$connection->getPdo()->quote($row->migration)}, {$row->batch})")
            ->all());
    }
}
