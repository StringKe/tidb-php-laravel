<?php

namespace StringKe\TidbPhp\Laravel\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;
use InvalidArgumentException;
use StringKe\TidbPhp\Laravel\UnsupportedFeatureException;

trait CompilesTidbTableOptions
{
    /**
     * @var list<string>
     */
    protected array $tableOptions = ['shardRowIdBits', 'preSplitRegions', 'autoIdCache', 'autoRandomBase', 'ttl', 'placementPolicy'];

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileShardRowIdBits(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileAlterOption($blueprint, $command);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compilePreSplitRegions(Blueprint $blueprint, Fluent $command): string
    {
        throw UnsupportedFeatureException::because('changing PRE_SPLIT_REGIONS on an existing table', 'it can only be set when the table is created.');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileAutoIdCache(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileAlterOption($blueprint, $command);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileAutoRandomBase(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileAlterOption($blueprint, $command);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileTtl(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileAlterOption($blueprint, $command);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compilePlacementPolicy(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileAlterOption($blueprint, $command);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileRemoveTtl(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' remove ttl';
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileTiflashReplica(Blueprint $blueprint, Fluent $command): string
    {
        $sql = 'alter table '.$this->wrapTable($blueprint).' set tiflash replica '.$this->nonNegative($command->get('count'), 'TiFlash replica count');

        $labels = $command->array('labels');

        return $labels === [] ? $sql : $sql.' location labels '.$this->quoteString($labels);
    }

    protected function compileTableOptions(Blueprint $blueprint): string
    {
        $options = [];

        foreach ($blueprint->getCommands() as $command) {
            if (in_array($command->get('name'), $this->tableOptions, true)) {
                $options[] = $this->compileTableOption($command);
                $command->set('shouldBeSkipped', true);
            }
        }

        return $options === [] ? '' : ' '.implode(' ', $options);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    private function compileAlterOption(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' '.$this->compileTableOption($command);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    private function compileTableOption(Fluent $command): string
    {
        return match ($command->get('name')) {
            'shardRowIdBits' => 'shard_row_id_bits = '.$this->nonNegative($command->get('value'), 'SHARD_ROW_ID_BITS'),
            'preSplitRegions' => 'pre_split_regions = '.$this->nonNegative($command->get('value'), 'PRE_SPLIT_REGIONS'),
            'autoIdCache' => 'auto_id_cache = '.$this->nonNegative($command->get('value'), 'AUTO_ID_CACHE'),
            'autoRandomBase' => 'auto_random_base = '.$this->nonNegative($command->get('value'), 'AUTO_RANDOM_BASE'),
            'placementPolicy' => 'placement policy = '.($command->filled('value') ? $this->wrapValue($command->string('value')->value()) : 'default'),
            'ttl' => $this->compileTtlOption($command),
            default => throw new InvalidArgumentException('Unknown table option ['.$command->string('name').'].'),
        };
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    private function compileTtlOption(Fluent $command): string
    {
        $sql = [];

        if ($command->filled('column')) {
            $interval = strtolower($command->string('interval')->value());

            if (preg_match('/^\d+\s+(microsecond|second|minute|hour|day|week|month|quarter|year)$/', $interval) !== 1) {
                throw new InvalidArgumentException("The TTL interval [{$interval}] must look like \"90 day\".");
            }

            $sql[] = 'ttl = '.$this->wrap($command->string('column')->value()).' + interval '.$interval;
        }

        if ($command->get('enable') !== null) {
            $sql[] = "ttl_enable = '".($command->boolean('enable') ? 'ON' : 'OFF')."'";
        }

        if ($command->filled('jobInterval')) {
            $jobInterval = $command->string('jobInterval')->value();

            if (preg_match('/^\d+(ms|s|m|h|d)$/', $jobInterval) !== 1) {
                throw new InvalidArgumentException("The TTL job interval [{$jobInterval}] must look like \"1h\".");
            }

            $sql[] = "ttl_job_interval = '{$jobInterval}'";
        }

        if ($sql === []) {
            throw new InvalidArgumentException('The TTL command sets nothing.');
        }

        return implode(' ', $sql);
    }

    private function nonNegative(mixed $value, string $option): int
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("{$option} must be a non-negative integer.");
        }

        return $value;
    }
}
