<?php

namespace StringKe\TidbPhp\Laravel\Concerns;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

trait CompilesTidbJson
{
    /**
     * @param  array<string, mixed>  $where
     */
    protected function whereNull(Builder $query, $where)
    {
        $column = (string) $this->getValue($where['column']);

        if ($this->isJsonSelector($column)) {
            [$field, $path] = $this->wrapJsonFieldAndPath($column);

            return '(json_extract('.$field.$path.') is null OR json_type(json_extract('.$field.$path.')) = \'NULL\')';
        }

        return parent::whereNull($query, $where);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    protected function whereNotNull(Builder $query, $where)
    {
        $column = (string) $this->getValue($where['column']);

        if ($this->isJsonSelector($column)) {
            [$field, $path] = $this->wrapJsonFieldAndPath($column);

            return '(json_extract('.$field.$path.') is not null AND json_type(json_extract('.$field.$path.')) != \'NULL\')';
        }

        return parent::whereNotNull($query, $where);
    }

    protected function compileJsonContains($column, $value)
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'json_contains('.$field.', '.$value.$path.')';
    }

    protected function compileJsonOverlaps($column, $value)
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'json_overlaps('.$field.', '.$value.$path.')';
    }

    protected function compileJsonContainsKey($column)
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'ifnull(json_contains_path('.$field.', \'one\''.$path.'), 0)';
    }

    protected function compileJsonLength($column, $operator, $value)
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'json_length('.$field.$path.') '.$operator.' '.$value;
    }

    public function compileJsonValueCast($value)
    {
        return 'cast('.$value.' as json)';
    }

    /**
     * @param  array<mixed>  $values
     */
    protected function compileUpdateColumns(Builder $query, array $values)
    {
        return (new Collection($values))->map(function ($value, $key) {
            if ($this->isJsonSelector($key)) {
                return $this->compileJsonUpdateColumn($key, $value);
            }

            return $this->wrap($key).' = '.$this->parameter($value);
        })->implode(', ');
    }

    /**
     * @param  string  $key
     */
    protected function compileJsonUpdateColumn($key, mixed $value): string
    {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_array($value)) {
            $value = 'cast(? as json)';
        } else {
            $value = $this->parameter($value);
        }

        [$field, $path] = $this->wrapJsonFieldAndPath($key);

        return "{$field} = json_set({$field}{$path}, {$value})";
    }

    /**
     * Booleans are written into JSON updates as literals, arrays are bound as JSON text.
     *
     * @param  array<mixed>  $bindings
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    public function prepareBindingsForUpdate(array $bindings, array $values)
    {
        $values = (new Collection($values))
            ->reject(fn ($value, $column) => $this->isJsonSelector($column) && is_bool($value))
            ->map(fn ($value) => is_array($value) ? json_encode($value) : $value)
            ->all();

        return parent::prepareBindingsForUpdate($bindings, $values);
    }

    protected function wrapJsonSelector($value)
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return 'json_unquote(json_extract('.$field.$path.'))';
    }

    protected function wrapJsonBooleanSelector($value)
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return 'json_extract('.$field.$path.')';
    }
}
