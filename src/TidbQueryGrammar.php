<?php

namespace StringKe\TidbPhp\Laravel;

use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\IndexHint;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Query\JoinLateralClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use StringKe\TidbPhp\Laravel\Concerns\CompilesTidbJson;

class TidbQueryGrammar extends Grammar
{
    use CompilesTidbJson;

    private const string LOCK_PATTERN = '/^for update(?: of [\w`$.]+(?:\s*,\s*[\w`$.]+)*)?(?: nowait| wait \d+)?$/i';

    /**
     * @param  array{function: string, columns: array<Expression|string>}  $aggregate
     */
    protected function compileAggregate(Builder $query, $aggregate)
    {
        return Str::replaceFirst('select ', 'select '.$this->compileHints($query), parent::compileAggregate($query, $aggregate));
    }

    /**
     * @param  array<Expression|string>  $columns
     */
    protected function compileColumns(Builder $query, $columns)
    {
        $sql = parent::compileColumns($query, $columns);

        return $sql === null ? null : Str::replaceFirst('select ', 'select '.$this->compileHints($query), $sql);
    }

    protected function compileUnionAggregate(Builder $query)
    {
        $sql = parent::compileAggregate($query, $query->aggregate);

        $query->aggregate = null;

        return $sql.' from ('.$this->compileSelect($query).') as '.$this->wrapTable('temp_table');
    }

    /**
     * @param  Expression|string  $table
     */
    protected function compileFrom(Builder $query, $table)
    {
        return 'from '.$this->wrapTable($table).$this->compileAsOf($query, $table);
    }

    /**
     * @param  array<int, JoinClause>  $joins
     */
    protected function compileJoins(Builder $query, $joins)
    {
        return (new Collection($joins))->map(function ($join) use ($query) {
            $table = $this->wrapTable($join->table).$this->compileAsOf($query, $join->table);

            $nestedJoins = is_null($join->joins) ? '' : ' '.$this->compileJoins($query, $join->joins);

            $tableAndNestedJoins = is_null($join->joins) ? $table : '('.$table.$nestedJoins.')';

            if ($join instanceof JoinLateralClause) {
                return $this->compileJoinLateral($join, $tableAndNestedJoins);
            }

            $joinWord = $join->type === 'straight_join' ? '' : ' join';

            return trim("{$join->type}{$joinWord} {$tableAndNestedJoins} {$this->compileWheres($join)}");
        })->implode(' ');
    }

    protected function supportsStraightJoins()
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $where
     */
    protected function whereBinary(Builder $query, $where)
    {
        $where['operator'] = ($where['not'] ? '!=' : '=').' binary';

        return $this->whereBasic($query, $where);
    }

    /**
     * utf8mb4_bin is the TiDB default collation, so a plain LIKE is case sensitive there.
     *
     * @param  array<string, mixed>  $where
     */
    protected function whereLike(Builder $query, $where)
    {
        $not = $where['not'] ? 'not ' : '';

        if ($where['caseSensitive']) {
            return $this->wrap($where['column']).' '.$not.'like binary '.$this->parameter($where['value']);
        }

        return 'lower('.$this->wrap($where['column']).') '.$not.'like lower('.$this->parameter($where['value']).')';
    }

    /**
     * @param  array<string, mixed>  $where
     */
    protected function whereNullSafeEquals(Builder $query, $where)
    {
        return $this->wrap($where['column']).' <=> '.$this->parameter($where['value']);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    public function whereFullText(Builder $query, $where)
    {
        throw UnsupportedFeatureException::because('full-text search', 'MATCH ... AGAINST is not implemented.');
    }

    /**
     * @param  IndexHint  $indexHint
     */
    protected function compileIndexHint(Builder $query, $indexHint): string
    {
        $index = $indexHint->index;

        foreach (array_map('trim', explode(',', $index)) as $name) {
            if (preg_match('/^[a-zA-Z0-9_$]+$/', $name) !== 1) {
                throw new InvalidArgumentException('Index name contains invalid characters.');
            }
        }

        return match ($indexHint->type) {
            'hint' => "use index ({$index})",
            'force' => "force index ({$index})",
            default => "ignore index ({$index})",
        };
    }

    /**
     * @param  array<mixed>  $values
     */
    public function compileInsert(Builder $query, array $values)
    {
        if (empty($values)) {
            $values = [[]];
        }

        return parent::compileInsert($query, $values);
    }

    /**
     * @param  array<mixed>  $values
     */
    public function compileInsertOrIgnore(Builder $query, array $values)
    {
        return Str::replaceFirst('insert', 'insert ignore', $this->compileInsert($query, $values));
    }

    /**
     * @param  array<string>  $columns
     */
    public function compileInsertOrIgnoreUsing(Builder $query, array $columns, string $sql)
    {
        return Str::replaceFirst('insert', 'insert ignore', $this->compileInsertUsing($query, $columns, $sql));
    }

    /**
     * TiDB parses the INSERT ... AS alias row alias but does not bind it, so the update always uses VALUES().
     *
     * @param  array<mixed>  $values
     * @param  array<string>  $uniqueBy
     * @param  array<mixed>  $update
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update)
    {
        $columns = (new Collection($update))->map(function ($value, $key) {
            return is_numeric($key)
                ? $this->wrap($value).' = values('.$this->wrap($value).')'
                : $this->wrap($key).' = '.$this->parameter($value);
        })->implode(', ');

        return $this->compileInsert($query, $values).' on duplicate key update '.$columns;
    }

    /**
     * @param  array<mixed>  $values
     */
    public function compileUpdate(Builder $query, array $values)
    {
        $this->ensureNotStale($query);

        return parent::compileUpdate($query, $values);
    }

    protected function compileUpdateWithoutJoins(Builder $query, $table, $columns, $where)
    {
        return trim("update {$this->compileHints($query)}{$table} set {$columns} {$where}".$this->compileOrdersAndLimit($query));
    }

    protected function compileUpdateWithJoins(Builder $query, $table, $columns, $where)
    {
        $joins = $this->compileJoins($query, $query->joins);

        return "update {$this->compileHints($query)}{$table} {$joins} set {$columns} {$where}";
    }

    public function compileDelete(Builder $query)
    {
        $this->ensureNotStale($query);

        return parent::compileDelete($query);
    }

    protected function compileDeleteWithoutJoins(Builder $query, $table, $where)
    {
        return trim("delete {$this->compileHints($query)}from {$table} {$where}".$this->compileOrdersAndLimit($query));
    }

    protected function compileDeleteWithJoins(Builder $query, $table, $where)
    {
        if (! empty($query->orders) || isset($query->limit)) {
            throw UnsupportedFeatureException::because('ORDER BY or LIMIT on a multi-table DELETE', 'the statement is a syntax error.');
        }

        $alias = last(explode(' as ', $table));

        $joins = $this->compileJoins($query, $query->joins);

        return "delete {$this->compileHints($query)}{$alias} from {$table} {$joins} {$where}";
    }

    /**
     * @param  bool|string  $value
     */
    protected function compileLock(Builder $query, $value)
    {
        if ($value === true) {
            return 'for update';
        }

        if ($value === false) {
            throw UnsupportedFeatureException::because('shared locks', 'LOCK IN SHARE MODE either fails or takes no lock, depending on tidb_enable_noop_functions.');
        }

        if (preg_match(self::LOCK_PATTERN, trim($value)) !== 1) {
            throw UnsupportedFeatureException::because("the lock clause [{$value}]", 'only FOR UPDATE [OF ...] [NOWAIT | WAIT n] takes locks; SKIP LOCKED and shared locks are ignored.');
        }

        return trim($value);
    }

    public function compileRandom($seed)
    {
        if ($seed === '') {
            return 'RAND()';
        }

        if (! is_numeric($seed)) {
            throw new InvalidArgumentException('The seed value must be numeric.');
        }

        return 'RAND('.(int) $seed.')';
    }

    public function compileVectorDistanceExpression($column)
    {
        return 'vec_cosine_distance('.$this->wrap($column).', ?)';
    }

    public function supportsVectorDistance()
    {
        return true;
    }

    /**
     * performance_schema is mostly empty on TiDB; the cluster process list covers every TiDB server.
     */
    public function compileThreadCount()
    {
        return 'select count(*) as `Value` from information_schema.cluster_processlist';
    }

    public function compileTimestamp(DateTimeInterface|Expression $at): string
    {
        return $at instanceof DateTimeInterface
            ? 'from_unixtime('.$at->format('U.u').')'
            : (string) $this->getValue($at);
    }

    public function compileBatch(string $column, int $size, bool $dryRun): string
    {
        return 'batch on '.$this->wrap($column).' limit '.$size.($dryRun ? ' dry run' : '');
    }

    protected function wrapValue($value)
    {
        return $value === '*' ? $value : '`'.str_replace('`', '``', $value).'`';
    }

    private function compileHints(Builder $query): string
    {
        $hints = $query instanceof TidbQueryBuilder ? $query->optimizerHints : [];

        if ($query->timeout !== null) {
            $hints[] = 'MAX_EXECUTION_TIME('.($query->timeout * 1000).')';
        }

        return $hints === [] ? '' : '/*+ '.implode(' ', $hints).' */ ';
    }

    /**
     * @param  Expression|string  $table
     */
    private function compileAsOf(Builder $query, $table): string
    {
        if (! $query instanceof TidbQueryBuilder || $query->asOfTimestamp === null || $this->isExpression($table)) {
            return '';
        }

        return ' as of timestamp '.$query->asOfTimestamp;
    }

    private function compileOrdersAndLimit(Builder $query): string
    {
        $sql = '';

        if (! empty($query->orders)) {
            $sql .= ' '.$this->compileOrders($query, $query->orders);
        }

        if (isset($query->limit)) {
            $sql .= ' '.$this->compileLimit($query, $query->limit);
        }

        return $sql;
    }

    private function ensureNotStale(Builder $query): void
    {
        if ($query instanceof TidbQueryBuilder && $query->asOfTimestamp !== null) {
            throw new LogicException('A query reading with asOf() is read-only.');
        }
    }
}
