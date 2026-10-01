<?php

namespace StringKe\TidbPhp\Laravel;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;

class TidbBlueprint extends Blueprint
{
    /**
     * @param  string  $type
     * @param  string  $name
     * @param  array<string, mixed>  $parameters
     */
    public function addColumn($type, $name, array $parameters = []): TidbColumnDefinition
    {
        $definition = new TidbColumnDefinition(array_merge(['type' => $type, 'name' => $name], $parameters));

        $this->addColumnDefinition($definition);

        return $definition;
    }

    /**
     * @param  string  $column
     * @param  bool  $autoIncrement
     * @param  bool  $unsigned
     */
    public function bigInteger($column, $autoIncrement = false, $unsigned = false): TidbColumnDefinition
    {
        return $this->addColumn('bigInteger', $column, ['autoIncrement' => $autoIncrement, 'unsigned' => $unsigned]);
    }

    /**
     * @param  string|array<array-key, mixed>  $columns
     * @param  string|null  $name
     * @param  string|null  $algorithm
     */
    public function primary($columns, $name = null, $algorithm = null): TidbIndexDefinition
    {
        return $this->indexCommand('primary', $columns, $name, $algorithm);
    }

    /**
     * @param  string|array<array-key, mixed>  $columns
     * @param  string|null  $name
     * @param  string|null  $algorithm
     */
    public function unique($columns, $name = null, $algorithm = null): TidbIndexDefinition
    {
        return $this->indexCommand('unique', $columns, $name, $algorithm);
    }

    /**
     * @param  string|array<array-key, mixed>  $columns
     * @param  string|null  $name
     * @param  string|null  $algorithm
     */
    public function index($columns, $name = null, $algorithm = null): TidbIndexDefinition
    {
        return $this->indexCommand('index', $columns, $name, $algorithm);
    }

    /**
     * @param  literal-string  $expression
     * @param  string  $name
     */
    public function rawIndex($expression, $name): TidbIndexDefinition
    {
        return $this->index([new Expression($expression)], $name);
    }

    /**
     * @param  string  $column
     * @param  string|null  $name
     */
    public function vectorIndex($column, $name = null): TidbIndexDefinition
    {
        return $this->indexCommand('vectorIndex', $column, $name, 'hnsw', 'vector_cosine_ops');
    }

    /**
     * @param  string  $type
     * @param  string|array<array-key, mixed>  $columns
     * @param  string|null  $index
     * @param  string|null  $algorithm
     * @param  string|null  $operatorClass
     */
    protected function indexCommand($type, $columns, $index, $algorithm = null, $operatorClass = null): TidbIndexDefinition
    {
        $columns = (array) $columns;

        $this->commands[] = $definition = new TidbIndexDefinition([
            'name' => $type,
            'index' => $index ?: $this->createIndexName($type, $columns),
            'columns' => $columns,
            'algorithm' => $algorithm,
            'operatorClass' => $operatorClass,
        ]);

        return $definition;
    }

    /**
     * A signed BIGINT primary key filled by AUTO_RANDOM, on a clustered primary key.
     */
    public function autoRandom(string $column = 'id', ?int $shardBits = null, ?int $rangeBits = null): TidbColumnDefinition
    {
        $definition = $this->bigInteger($column)->autoRandom($shardBits, $rangeBits);

        $this->primary($column);

        return $definition;
    }

    /**
     * @return Fluent<string, mixed>
     */
    public function shardRowIdBits(int $bits): Fluent
    {
        return $this->addCommand('shardRowIdBits', ['value' => $bits]);
    }

    /**
     * @return Fluent<string, mixed>
     */
    public function preSplitRegions(int $regions): Fluent
    {
        return $this->addCommand('preSplitRegions', ['value' => $regions]);
    }

    /**
     * @return Fluent<string, mixed>
     */
    public function autoIdCache(int $size): Fluent
    {
        return $this->addCommand('autoIdCache', ['value' => $size]);
    }

    /**
     * @return Fluent<string, mixed>
     */
    public function autoRandomBase(int $base): Fluent
    {
        return $this->addCommand('autoRandomBase', ['value' => $base]);
    }

    /**
     * Rows expire once the time column plus the interval ("90 day") has passed.
     *
     * @return Fluent<string, mixed>
     */
    public function ttl(string $column, string $interval, ?bool $enable = null, ?string $jobInterval = null): Fluent
    {
        return $this->addCommand('ttl', ['column' => $column, 'interval' => $interval, 'enable' => $enable, 'jobInterval' => $jobInterval]);
    }

    /**
     * @return Fluent<string, mixed>
     */
    public function ttlEnable(bool $enable = true): Fluent
    {
        return $this->addCommand('ttl', ['enable' => $enable]);
    }

    /**
     * @return Fluent<string, mixed>
     */
    public function ttlJobInterval(string $interval): Fluent
    {
        return $this->addCommand('ttl', ['jobInterval' => $interval]);
    }

    /**
     * @return Fluent<string, mixed>
     */
    public function removeTtl(): Fluent
    {
        return $this->addCommand('removeTtl');
    }

    /**
     * Null switches the table back to the default placement.
     *
     * @return Fluent<string, mixed>
     */
    public function placementPolicy(?string $policy): Fluent
    {
        return $this->addCommand('placementPolicy', ['value' => $policy]);
    }

    /**
     * @param  list<string>  $labels
     * @return Fluent<string, mixed>
     */
    public function tiflashReplica(int $count, array $labels = []): Fluent
    {
        return $this->addCommand('tiflashReplica', ['count' => $count, 'labels' => $labels]);
    }

    /**
     * @return Fluent<string, mixed>
     */
    public function makeIndexVisible(string $index): Fluent
    {
        return $this->addCommand('indexVisibility', ['index' => $index, 'visible' => true]);
    }

    /**
     * @return Fluent<string, mixed>
     */
    public function makeIndexInvisible(string $index): Fluent
    {
        return $this->addCommand('indexVisibility', ['index' => $index, 'visible' => false]);
    }
}
