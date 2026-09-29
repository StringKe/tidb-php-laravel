<?php

namespace StringKe\TidbPhp\Laravel\Concerns;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use InvalidArgumentException;
use StringKe\TidbPhp\Laravel\UnsupportedFeatureException;

trait CompilesTidbColumnModifiers
{
    protected function modifyVirtualAs(Blueprint $blueprint, ColumnDefinition $column): string
    {
        $expression = $this->generationExpression($column, 'virtualAs');

        return $expression === null ? '' : " as ({$expression})";
    }

    protected function modifyStoredAs(Blueprint $blueprint, ColumnDefinition $column): string
    {
        $expression = $this->generationExpression($column, 'storedAs');

        return $expression === null ? '' : " as ({$expression}) stored";
    }

    protected function modifyUnsigned(Blueprint $blueprint, ColumnDefinition $column): string
    {
        return $column->boolean('unsigned') ? ' unsigned' : '';
    }

    protected function modifyCharset(Blueprint $blueprint, ColumnDefinition $column): string
    {
        return $column->filled('charset') ? ' character set '.$column->string('charset') : '';
    }

    protected function modifyCollate(Blueprint $blueprint, ColumnDefinition $column): string
    {
        return $column->filled('collation') ? " collate '".$column->string('collation')."'" : '';
    }

    protected function modifyNullable(Blueprint $blueprint, ColumnDefinition $column): string
    {
        $generated = $this->generationExpression($column, 'virtualAs') !== null || $this->generationExpression($column, 'storedAs') !== null;

        if (! $generated) {
            return $column->boolean('nullable') ? ' null' : ' not null';
        }

        return $column->get('nullable') === false ? ' not null' : '';
    }

    protected function modifyInvisible(Blueprint $blueprint, ColumnDefinition $column): string
    {
        if ($column->get('invisible') !== null) {
            throw UnsupportedFeatureException::because('invisible columns', 'the INVISIBLE column attribute is a syntax error.');
        }

        return '';
    }

    protected function modifyDefault(Blueprint $blueprint, ColumnDefinition $column): string
    {
        if ($column->get('default') !== null) {
            if ($column->get('autoRandom')) {
                throw new InvalidArgumentException("The AUTO_RANDOM column [{$column->string('name')}] cannot have a default value.");
            }

            return ' default '.$this->getDefaultValue($column->get('default'));
        }

        return $column->boolean('useCurrent') ? ' default '.$this->currentValue($column) : '';
    }

    protected function modifyOnUpdate(Blueprint $blueprint, ColumnDefinition $column): string
    {
        $onUpdate = $column->get('onUpdate');

        if ($onUpdate instanceof Expression) {
            return ' on update '.$this->getValue($onUpdate);
        }

        if (is_string($onUpdate)) {
            return ' on update '.$onUpdate;
        }

        return $column->boolean('useCurrentOnUpdate') ? ' on update '.$this->currentValue($column) : '';
    }

    protected function modifyIncrement(Blueprint $blueprint, ColumnDefinition $column): string
    {
        if (! in_array($column->get('type'), $this->serials, true) || ! $column->boolean('autoIncrement')) {
            return '';
        }

        if ($column->get('autoRandom')) {
            throw new InvalidArgumentException("The column [{$column->string('name')}] cannot be both AUTO_INCREMENT and AUTO_RANDOM.");
        }

        return $this->hasCommand($blueprint, 'primary') || ($column->boolean('change') && ! $column->boolean('primary'))
            ? ' auto_increment'
            : ' auto_increment primary key';
    }

    /**
     * autoRandom() takes the connection's auto_random config, autoRandom(6) sets the shard bits, autoRandom([6, 54]) the shard and range bits.
     */
    protected function modifyAutoRandom(Blueprint $blueprint, ColumnDefinition $column): string
    {
        $autoRandom = $column->get('autoRandom');

        if (! $autoRandom) {
            return '';
        }

        if ($column->get('type') !== 'bigInteger') {
            throw new InvalidArgumentException("The AUTO_RANDOM column [{$column->string('name')}] must be a big integer.");
        }

        [$shard, $range] = array_pad(array_values((array) ($autoRandom === true ? $this->defaultAutoRandomBits() : $autoRandom)), 2, null);

        if (($shard !== null && ! is_int($shard)) || ($range !== null && ! is_int($range)) || ($shard === null && $range !== null)) {
            throw new InvalidArgumentException("The AUTO_RANDOM bits of [{$column->string('name')}] must be integers, the range bits only after the shard bits.");
        }

        $sql = match (true) {
            $shard === null => ' auto_random',
            $range === null => " auto_random({$shard})",
            default => " auto_random({$shard}, {$range})",
        };

        return $blueprint->creating() && ! $this->hasCommand($blueprint, 'primary') ? $sql.' primary key clustered' : $sql;
    }

    protected function modifyComment(Blueprint $blueprint, ColumnDefinition $column): string
    {
        return $column->get('comment') === null ? '' : " comment '".addslashes($column->string('comment')->value())."'";
    }

    protected function modifyFirst(Blueprint $blueprint, ColumnDefinition $column): string
    {
        return $column->boolean('first') ? ' first' : '';
    }

    protected function modifyAfter(Blueprint $blueprint, ColumnDefinition $column): string
    {
        return $column->filled('after') ? ' after '.$this->wrap($column->string('after')->value()) : '';
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    protected function defaultAutoRandomBits(): array
    {
        $config = (array) $this->connection->getConfig('auto_random');

        return [$config['shard_bits'] ?? null, $config['range_bits'] ?? null];
    }

    private function currentValue(ColumnDefinition $column): string
    {
        if ($column->get('type') === 'date') {
            return '(CURRENT_DATE())';
        }

        return $column->filled('precision') ? 'CURRENT_TIMESTAMP('.$column->integer('precision').')' : 'CURRENT_TIMESTAMP';
    }

    private function generationExpression(ColumnDefinition $column, string $kind): ?string
    {
        $json = $column->get($kind.'Json');

        if (is_string($json)) {
            return $this->isJsonSelector($json) ? $this->wrapJsonSelector($json) : $json;
        }

        $expression = $column->get($kind);

        return match (true) {
            $expression instanceof Expression => (string) $this->getValue($expression),
            is_string($expression) => $expression,
            default => null,
        };
    }
}
