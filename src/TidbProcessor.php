<?php

namespace StringKe\TidbPhp\Laravel;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use LogicException;

/**
 * Next to the Laravel keys, tables report clustered and row_id_sharding, columns auto_random, and indexes clustered, visible, global, expressions and comment.
 */
class TidbProcessor extends Processor
{
    /**
     * TiDB updates LAST_INSERT_ID() for implicitly allocated AUTO_RANDOM values too.
     *
     * @param  array<mixed>  $values
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $connection = $query->getConnection();

        if (! $connection instanceof TidbConnection) {
            return parent::processInsertGetId($query, $sql, $values, $sequence);
        }

        $connection->insert($sql, $values, $sequence);

        $id = $connection->getLastInsertId();

        return is_numeric($id) ? (int) $id : throw new LogicException('The server returned no insert id.');
    }

    public function processTables($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'schema' => $result->schema ?? null,
                'schema_qualified_name' => isset($result->schema) ? $result->schema.'.'.$result->name : $result->name,
                'size' => isset($result->size) ? (int) $result->size : null,
                'comment' => $result->comment ?? null,
                'collation' => $result->collation ?? null,
                'engine' => $result->engine ?? null,
                'clustered' => ($result->pk_type ?? null) === 'CLUSTERED',
                'row_id_sharding' => $result->sharding ?? null,
            ];
        }, $results);
    }

    public function processColumns($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'type_name' => $result->type_name,
                'type' => $result->type,
                'collation' => $result->collation,
                'nullable' => $result->nullable === 'YES',
                'default' => $result->default,
                'auto_increment' => $result->extra === 'auto_increment',
                'auto_random' => (bool) $result->auto_random,
                'comment' => $result->comment ?: null,
                'generation' => $result->expression ? [
                    'type' => match ($result->extra) {
                        'STORED GENERATED' => 'stored',
                        'VIRTUAL GENERATED' => 'virtual',
                        default => null,
                    },
                    'expression' => $result->expression,
                ] : null,
            ];
        }, $results);
    }

    public function processIndexes($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $name = strtolower($result->name),
                'columns' => $result->columns ? explode(',', $result->columns) : [],
                'type' => 'btree',
                'unique' => (bool) $result->unique,
                'primary' => $name === 'primary',
                'clustered' => $result->clustered === 'YES',
                'visible' => $result->visible === 'YES',
                'global' => (bool) $result->global,
                'expressions' => $result->expressions ? explode("\n", $result->expressions) : [],
                'comment' => $result->comment ?: null,
            ];
        }, $results);
    }

    public function processForeignKeys($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'columns' => explode(',', $result->columns),
                'foreign_schema' => $result->foreign_schema,
                'foreign_table' => $result->foreign_table,
                'foreign_columns' => explode(',', $result->foreign_columns),
                'on_update' => strtolower($result->on_update),
                'on_delete' => strtolower($result->on_delete),
            ];
        }, $results);
    }
}
