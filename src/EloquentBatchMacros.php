<?php

namespace StringKe\TidbPhp\Laravel;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Eloquent forwards the batch statements to the base query, where they would otherwise return the builder instead of the job summary.
 * Global scopes apply through toBase(); batchUpdate stamps updated_at like update(); batchDelete removes rows even on soft-deleting models.
 */
final class EloquentBatchMacros
{
    public static function register(): void
    {
        Builder::macro('batchUpdate', function (string $column, int $size, array $values, bool $dryRun = false): array {
            return EloquentBatchMacros::base($this)->batchUpdate($column, $size, $this->addUpdatedAtColumn($values), $dryRun);
        });

        Builder::macro('batchDelete', function (string $column, int $size, bool $dryRun = false): array {
            return EloquentBatchMacros::base($this)->batchDelete($column, $size, $dryRun);
        });

        Builder::macro('batchInsertUsing', function (string $column, int $size, array $columns, mixed $query, bool $dryRun = false): array {
            return EloquentBatchMacros::base($this)->batchInsertUsing($column, $size, array_values($columns), $query, $dryRun);
        });
    }

    /**
     * @param  Builder<Model>  $builder
     */
    public static function base(Builder $builder): TidbQueryBuilder
    {
        $query = $builder->toBase();

        return $query instanceof TidbQueryBuilder ? $query : throw new LogicException('Batch statements need a tidb connection.');
    }
}
