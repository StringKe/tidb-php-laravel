<?php

namespace StringKe\TidbPhp\Laravel;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use UnitEnum;

use function Illuminate\Support\enum_value;

/**
 * @property TidbQueryGrammar $grammar
 * @property TidbConnection $connection
 */
class TidbQueryBuilder extends Builder
{
    /**
     * Optimizer hints placed right after SELECT, UPDATE or DELETE.
     *
     * @var list<string>
     */
    public array $optimizerHints = [];

    /**
     * SQL expression of the AS OF TIMESTAMP clause applied to the table and every joined table.
     */
    public ?string $asOfTimestamp = null;

    /**
     * @return $this
     */
    public function hint(string ...$hints): static
    {
        foreach ($hints as $hint) {
            $hint = trim($hint);

            if ($hint === '' || str_contains($hint, '*/') || str_contains($hint, '/*')) {
                throw new InvalidArgumentException('An optimizer hint must be non-empty and cannot contain comment delimiters.');
            }

            $this->optimizerHints[] = $hint;
        }

        return $this;
    }

    /**
     * Reads the table and every joined table as of the given time (stale read).
     *
     * @return $this
     */
    public function asOf(DateTimeInterface|ExpressionContract $at): static
    {
        $this->asOfTimestamp = $this->grammar->compileTimestamp($at);

        return $this;
    }

    /**
     * Splits the update into one transaction per batch of rows, ordered by the shard column.
     *
     * @param  array<string, mixed>  $values
     * @return list<object> the job summary, or the split statements with a dry run
     */
    public function batchUpdate(string $column, int $size, array $values, bool $dryRun = false): array
    {
        $this->applyBeforeQueryCallbacks();

        $values = (new Collection($values))->map(function ($value) {
            if (! $value instanceof Builder && ! $value instanceof EloquentBuilder && ! $value instanceof Relation) {
                return ['value' => $value, 'bindings' => match (true) {
                    $value instanceof Collection => $value->all(),
                    $value instanceof UnitEnum => enum_value($value),
                    default => $value,
                }];
            }

            [$query, $bindings] = $this->parseSub($value);

            return ['value' => new CompiledSql("({$query})"), 'bindings' => fn () => $bindings];
        });

        $sql = $this->grammar->compileUpdate($this, $values->map(fn ($value) => $value['value'])->all());
        $bindings = $this->grammar->prepareBindingsForUpdate($this->bindings, $values->map(fn ($value) => $value['bindings'])->all());

        return $this->runBatch($column, $size, $dryRun, $sql, $bindings);
    }

    /**
     * Splits the delete into one transaction per batch of rows, ordered by the shard column.
     *
     * @return list<object> the job summary, or the split statements with a dry run
     */
    public function batchDelete(string $column, int $size, bool $dryRun = false): array
    {
        $this->applyBeforeQueryCallbacks();

        return $this->runBatch($column, $size, $dryRun, $this->grammar->compileDelete($this), $this->grammar->prepareBindingsForDelete($this->bindings));
    }

    /**
     * Splits INSERT INTO ... SELECT into batches; the shard column belongs to the selected table.
     *
     * @param  list<string>  $columns
     * @param  Closure|Builder|EloquentBuilder<*>|string  $query
     * @return list<object> the job summary, or the split statements with a dry run
     */
    public function batchInsertUsing(string $column, int $size, array $columns, $query, bool $dryRun = false): array
    {
        $this->applyBeforeQueryCallbacks();

        [$sql, $bindings] = $this->createSub($query);

        return $this->runBatch($column, $size, $dryRun, $this->grammar->compileInsertUsing($this, $columns, $sql), $bindings);
    }

    /**
     * @param  array<mixed>  $bindings
     * @return list<object>
     */
    private function runBatch(string $column, int $size, bool $dryRun, string $sql, array $bindings): array
    {
        if ($size < 1) {
            throw new InvalidArgumentException('The batch size must be at least 1.');
        }

        $sql = $this->grammar->compileBatch($column, $size, $dryRun).' '.$sql;

        $rows = array_values($this->connection->select($sql, $this->cleanBindings($bindings), false));

        if (! $dryRun) {
            $this->connection->recordsHaveBeenModified();
        }

        return $rows;
    }
}
