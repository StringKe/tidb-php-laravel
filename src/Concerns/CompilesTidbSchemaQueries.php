<?php

namespace StringKe\TidbPhp\Laravel\Concerns;

trait CompilesTidbSchemaQueries
{
    public function compileSchemas()
    {
        return 'select schema_name as name, schema_name = schema() as `default` from information_schema.schemata where '
            .$this->compileSchemaWhereClause(null, 'schema_name')
            .' order by schema_name';
    }

    public function compileTableExists($schema, $table)
    {
        return sprintf(
            "select exists (select 1 from information_schema.tables where table_schema = %s and table_name = %s and table_type = 'BASE TABLE') as `exists`",
            $this->schemaOrCurrent($schema),
            $this->quoteString($table)
        );
    }

    public function compileTables($schema)
    {
        return 'select table_name as `name`, table_schema as `schema`, (data_length + index_length) as `size`, '
            .'table_comment as `comment`, engine as `engine`, table_collation as `collation`, '
            .'tidb_pk_type as `pk_type`, tidb_row_id_sharding_info as `sharding` '
            ."from information_schema.tables where table_type = 'BASE TABLE' and "
            .$this->compileSchemaWhereClause($schema, 'table_schema')
            .' order by table_schema, table_name';
    }

    public function compileViews($schema)
    {
        return 'select table_name as `name`, table_schema as `schema`, view_definition as `definition` '
            .'from information_schema.views where '
            .$this->compileSchemaWhereClause($schema, 'table_schema')
            .' order by table_schema, table_name';
    }

    /**
     * @param  string|string[]|null  $schema
     */
    public function compileSequences($schema): string
    {
        return 'select table_name as `name`, table_schema as `schema` '
            ."from information_schema.tables where table_type = 'SEQUENCE' and "
            .$this->compileSchemaWhereClause($schema, 'table_schema')
            .' order by table_schema, table_name';
    }

    /**
     * AUTO_RANDOM is not reported in columns.extra; it can only sit on the first column of the clustered primary key.
     */
    public function compileColumns($schema, $table)
    {
        return sprintf(
            'select c.column_name as `name`, c.data_type as `type_name`, c.column_type as `type`, '
            .'c.collation_name as `collation`, c.is_nullable as `nullable`, '
            .'c.column_default as `default`, c.column_comment as `comment`, '
            .'c.generation_expression as `expression`, c.extra as `extra`, '
            ."(ifnull(t.tidb_row_id_sharding_info, '') like 'PK\\_AUTO\\_RANDOM\\_BITS=%%' and exists ("
            .'select 1 from information_schema.statistics s where s.table_schema = c.table_schema and s.table_name = c.table_name '
            ."and s.index_name = 'PRIMARY' and s.seq_in_index = 1 and s.column_name = c.column_name)) as `auto_random` "
            .'from information_schema.columns c join information_schema.tables t on t.table_schema = c.table_schema and t.table_name = c.table_name '
            .'where c.table_schema = %s and c.table_name = %s '
            .'order by c.ordinal_position asc',
            $this->schemaOrCurrent($schema),
            $this->quoteString($table)
        );
    }

    public function compileIndexes($schema, $table)
    {
        return sprintf(
            'select key_name as `name`, '
            .'group_concat(case when expression is null then column_name end order by seq_in_index separator \',\') as `columns`, '
            .'group_concat(expression order by seq_in_index separator \'\\n\') as `expressions`, '
            .'not non_unique as `unique`, max(clustered) as `clustered`, max(is_visible) as `visible`, '
            .'max(is_global) as `global`, max(index_comment) as `comment` '
            .'from information_schema.tidb_indexes where table_schema = %s and table_name = %s '
            .'group by key_name, non_unique',
            $this->schemaOrCurrent($schema),
            $this->quoteString($table)
        );
    }

    public function compileForeignKeys($schema, $table)
    {
        return sprintf(
            'select kc.constraint_name as `name`, '
            .'group_concat(kc.column_name order by kc.ordinal_position) as `columns`, '
            .'kc.referenced_table_schema as `foreign_schema`, '
            .'kc.referenced_table_name as `foreign_table`, '
            .'group_concat(kc.referenced_column_name order by kc.ordinal_position) as `foreign_columns`, '
            .'rc.update_rule as `on_update`, '
            .'rc.delete_rule as `on_delete` '
            .'from information_schema.key_column_usage kc join information_schema.referential_constraints rc '
            .'on kc.constraint_schema = rc.constraint_schema and kc.constraint_name = rc.constraint_name '
            .'where kc.table_schema = %s and kc.table_name = %s and kc.referenced_table_name is not null '
            .'group by kc.constraint_name, kc.referenced_table_schema, kc.referenced_table_name, rc.update_rule, rc.delete_rule',
            $this->schemaOrCurrent($schema),
            $this->quoteString($table)
        );
    }

    /**
     * @param  string|string[]|null  $schema
     */
    protected function compileSchemaWhereClause($schema, string $column): string
    {
        return $column.(match (true) {
            ! empty($schema) && is_array($schema) => ' in ('.$this->quoteString($schema).')',
            ! empty($schema) => ' = '.$this->quoteString($schema),
            default => " not in ('information_schema', 'metrics_schema', 'mysql', 'performance_schema', 'sys')",
        });
    }

    private function schemaOrCurrent(?string $schema): string
    {
        return $schema ? $this->quoteString($schema) : 'schema()';
    }
}
