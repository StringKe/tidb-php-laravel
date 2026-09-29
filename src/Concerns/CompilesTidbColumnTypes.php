<?php

namespace StringKe\TidbPhp\Laravel\Concerns;

use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Fluent;
use RuntimeException;
use StringKe\TidbPhp\Laravel\UnsupportedFeatureException;

trait CompilesTidbColumnTypes
{
    protected function typeChar(ColumnDefinition $column): string
    {
        return 'char('.$column->integer('length').')';
    }

    protected function typeString(ColumnDefinition $column): string
    {
        return 'varchar('.$column->integer('length').')';
    }

    protected function typeTinyText(ColumnDefinition $column): string
    {
        return 'tinytext';
    }

    protected function typeText(ColumnDefinition $column): string
    {
        return 'text';
    }

    protected function typeMediumText(ColumnDefinition $column): string
    {
        return 'mediumtext';
    }

    protected function typeLongText(ColumnDefinition $column): string
    {
        return 'longtext';
    }

    protected function typeBigInteger(ColumnDefinition $column): string
    {
        return 'bigint';
    }

    protected function typeInteger(ColumnDefinition $column): string
    {
        return 'int';
    }

    protected function typeMediumInteger(ColumnDefinition $column): string
    {
        return 'mediumint';
    }

    protected function typeTinyInteger(ColumnDefinition $column): string
    {
        return 'tinyint';
    }

    protected function typeSmallInteger(ColumnDefinition $column): string
    {
        return 'smallint';
    }

    protected function typeFloat(ColumnDefinition $column): string
    {
        return $column->filled('precision') ? 'float('.$column->integer('precision').')' : 'float';
    }

    protected function typeDouble(ColumnDefinition $column): string
    {
        return 'double';
    }

    protected function typeDecimal(ColumnDefinition $column): string
    {
        return 'decimal('.$column->integer('total').', '.$column->integer('places').')';
    }

    protected function typeBoolean(ColumnDefinition $column): string
    {
        return 'tinyint(1)';
    }

    protected function typeEnum(ColumnDefinition $column): string
    {
        return 'enum('.$this->quoteString($column->array('allowed')).')';
    }

    protected function typeSet(ColumnDefinition $column): string
    {
        return 'set('.$this->quoteString($column->array('allowed')).')';
    }

    protected function typeJson(ColumnDefinition $column): string
    {
        return 'json';
    }

    protected function typeJsonb(ColumnDefinition $column): string
    {
        return 'json';
    }

    protected function typeDate(ColumnDefinition $column): string
    {
        return 'date';
    }

    protected function typeDateTime(ColumnDefinition $column): string
    {
        return 'datetime'.$this->fractionalSeconds($column);
    }

    protected function typeDateTimeTz(ColumnDefinition $column): string
    {
        return $this->typeDateTime($column);
    }

    protected function typeTime(ColumnDefinition $column): string
    {
        return 'time'.$this->fractionalSeconds($column);
    }

    protected function typeTimeTz(ColumnDefinition $column): string
    {
        return $this->typeTime($column);
    }

    protected function typeTimestamp(ColumnDefinition $column): string
    {
        return 'timestamp'.$this->fractionalSeconds($column);
    }

    protected function typeTimestampTz(ColumnDefinition $column): string
    {
        return $this->typeTimestamp($column);
    }

    protected function typeYear(ColumnDefinition $column): string
    {
        if ($column->boolean('useCurrent')) {
            throw UnsupportedFeatureException::because('a current-year default on YEAR columns', 'YEAR does not accept an expression default.');
        }

        return 'year';
    }

    protected function typeBinary(ColumnDefinition $column): string
    {
        if ($column->filled('length')) {
            return ($column->boolean('fixed') ? 'binary(' : 'varbinary(').$column->integer('length').')';
        }

        return 'blob';
    }

    protected function typeUuid(ColumnDefinition $column): string
    {
        return 'char(36)';
    }

    protected function typeIpAddress(ColumnDefinition $column): string
    {
        return 'varchar(45)';
    }

    protected function typeMacAddress(ColumnDefinition $column): string
    {
        return 'varchar(17)';
    }

    protected function typeGeometry(ColumnDefinition $column): string
    {
        throw UnsupportedFeatureException::because('spatial column types', 'GEOMETRY and its subtypes are not implemented.');
    }

    protected function typeGeography(ColumnDefinition $column): string
    {
        return $this->typeGeometry($column);
    }

    /**
     * @param  Fluent<string, mixed>  $column
     */
    protected function typeComputed(Fluent $column): string
    {
        throw new RuntimeException('This database driver requires a type, see the virtualAs / storedAs modifiers.');
    }

    /**
     * @param  Fluent<string, mixed>  $column
     */
    protected function typeVector(Fluent $column): string
    {
        return $column->filled('dimensions') ? 'vector('.$column->integer('dimensions').')' : 'vector';
    }

    private function fractionalSeconds(ColumnDefinition $column): string
    {
        return $column->filled('precision') ? '('.$column->integer('precision').')' : '';
    }
}
