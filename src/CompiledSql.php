<?php

namespace StringKe\TidbPhp\Laravel;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * SQL the grammar has already compiled, such as a subquery, passed on without quoting.
 */
final readonly class CompiledSql implements Expression
{
    public function __construct(private string $sql) {}

    public function getValue(Grammar $grammar): string
    {
        return $this->sql;
    }
}
