<?php

namespace StringKe\TidbPhp\Laravel;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;
use Illuminate\Support\Stringable;
use InvalidArgumentException;
use StringKe\TidbPhp\Laravel\Concerns\CompilesTidbColumnModifiers;
use StringKe\TidbPhp\Laravel\Concerns\CompilesTidbColumnTypes;
use StringKe\TidbPhp\Laravel\Concerns\CompilesTidbSchemaQueries;
use StringKe\TidbPhp\Laravel\Concerns\CompilesTidbTableOptions;

class TidbSchemaGrammar extends Grammar
{
    use CompilesTidbColumnModifiers;
    use CompilesTidbColumnTypes;
    use CompilesTidbSchemaQueries;
    use CompilesTidbTableOptions;

    protected $modifiers = [
        'Unsigned', 'Charset', 'Collate', 'VirtualAs', 'StoredAs', 'Nullable',
        'Default', 'OnUpdate', 'Invisible', 'Increment', 'AutoRandom', 'Comment', 'After', 'First',
    ];

    /**
     * @var list<string>
     */
    protected array $serials = ['bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger'];

    /**
     * @var list<string>
     */
    protected $fluentCommands = ['AutoIncrementStartingValues'];

    private const array VECTOR_DISTANCES = [
        'vector_cosine_ops' => 'vec_cosine_distance',
        'cosine' => 'vec_cosine_distance',
        'vector_l2_ops' => 'vec_l2_distance',
        'l2' => 'vec_l2_distance',
    ];

    public function compileCreateDatabase($name)
    {
        $sql = parent::compileCreateDatabase($name);

        if ($charset = $this->connection->getConfig('charset')) {
            $sql .= ' default character set '.$this->wrapValue($charset);
        }

        if ($collation = $this->connection->getConfig('collation')) {
            $sql .= ' default collate '.$this->wrapValue($collation);
        }

        return $sql;
    }

    /**
     * The primary key, plain and unique indexes and the table options go into the CREATE statement itself.
     *
     * @param  Fluent<string, mixed>  $command
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        $definitions = $this->getColumns($blueprint);

        foreach ($blueprint->getCommands() as $index) {
            if ($index->boolean('shouldBeSkipped')) {
                continue;
            }

            $definition = match ($index->get('name')) {
                'primary' => $this->compilePrimaryDefinition($blueprint, $index),
                'unique' => $this->compileIndexDefinition($index, 'unique key'),
                'index' => $this->compileIndexDefinition($index, 'index'),
                default => null,
            };

            if ($definition !== null) {
                $definitions[] = $definition;
                $index->set('shouldBeSkipped', true);
            }
        }

        $sql = ($blueprint->temporary ? 'create temporary' : 'create').' table '.$this->wrapTable($blueprint).' ('.implode(', ', $definitions).')';

        $charset = $blueprint->charset ?: $this->connection->getConfig('charset');
        $collation = $blueprint->collation ?: $this->connection->getConfig('collation');
        $engine = $blueprint->engine ?: $this->connection->getConfig('engine');

        return $sql
            .($charset ? ' default character set '.$charset : '')
            .($collation ? " collate '{$collation}'" : '')
            .($engine ? ' engine = '.$engine : '')
            .$this->compileTableOptions($blueprint);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): string
    {
        $column = $this->commandColumn($command);

        if ($column->get('storedAs') !== null || $column->get('storedAsJson') !== null) {
            throw UnsupportedFeatureException::because('adding a stored generated column to an existing table', 'only virtual generated columns can be added.');
        }

        return 'alter table '.$this->wrapTable($blueprint).' add '.$this->getColumn($blueprint, $column).$this->compileAlgorithmAndLock($column, 'instant');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileAutoIncrementStartingValues(Blueprint $blueprint, Fluent $command): ?string
    {
        $column = $this->commandColumn($command);
        $value = $column->get('startingValue', $column->get('from'));

        return $column->boolean('autoIncrement') && is_int($value) ? 'alter table '.$this->wrapTable($blueprint).' auto_increment = '.$value : null;
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileChange(Blueprint $blueprint, Fluent $command): string
    {
        $column = $this->commandColumn($command);
        $renameTo = $column->get('renameTo');

        $sql = 'alter table '.$this->wrapTable($blueprint)
            .(is_string($renameTo) ? ' change '.$this->wrap($column).' '.$this->wrap($renameTo) : ' modify '.$this->wrap($column))
            .' '.$this->getType($column);

        return $this->addModifiers($sql, $blueprint, $column).$this->compileAlgorithmAndLock($column, 'instant');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compilePrimary(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' add '.$this->compilePrimaryDefinition($blueprint, $command).$this->compileAlgorithmAndLock($command, 'inplace');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' add '.$this->compileIndexDefinition($command, 'unique key').$this->compileAlgorithmAndLock($command, 'inplace');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileIndex(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' add '.$this->compileIndexDefinition($command, 'index').$this->compileAlgorithmAndLock($command, 'inplace');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileFulltext(Blueprint $blueprint, Fluent $command): string
    {
        throw UnsupportedFeatureException::because('FULLTEXT indexes', 'the index is accepted and then silently left out of the table.');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileSpatialIndex(Blueprint $blueprint, Fluent $command): string
    {
        throw UnsupportedFeatureException::because('SPATIAL indexes', 'spatial types are not implemented.');
    }

    /**
     * Vector indexes are built by TiFlash, so the table needs a TiFlash replica.
     *
     * @param  Fluent<string, mixed>  $command
     */
    public function compileVectorIndex(Blueprint $blueprint, Fluent $command): string
    {
        $columns = $command->array('columns');

        if (count($columns) !== 1 || ! is_string($columns[0] ?? null)) {
            throw new InvalidArgumentException('A vector index covers exactly one vector column.');
        }

        $operator = strtolower($command->string('operatorClass', 'cosine')->value());
        $distance = self::VECTOR_DISTANCES[$operator] ?? throw new InvalidArgumentException("Unknown vector distance [{$operator}], use cosine or l2.");

        if (strtolower($command->string('algorithm', 'hnsw')->value()) !== 'hnsw') {
            throw new InvalidArgumentException('TiDB builds vector indexes with HNSW only.');
        }

        return 'alter table '.$this->wrapTable($blueprint).' add vector index '.$this->wrap($command->string('index')->value())
            ." (({$distance}(".$this->wrap($columns[0]).'))) using hnsw'.$this->compileIndexComment($command);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table '.$this->wrapTable($blueprint);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table if exists '.$this->wrapTable($blueprint);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command): string
    {
        $columns = $this->prefixArray('drop', $this->wrapArray($command->array('columns')));

        return 'alter table '.$this->wrapTable($blueprint).' '.implode(', ', $columns).$this->compileAlgorithmAndLock($command, 'instant');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileDropPrimary(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' drop primary key';
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileDropIndex($blueprint, $command);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' drop index '.$this->wrap($command->string('index')->value());
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileDropVectorIndex(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileDropIndex($blueprint, $command);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command): string
    {
        return parent::compileForeign($blueprint, $command).$this->compileAlgorithmAndLock($command, 'inplace');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' drop foreign key '.$this->wrap($command->string('index')->value());
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        return 'rename table '.$this->wrapTable($blueprint).' to '.$this->wrapTable($command->string('to')->value());
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileRenameIndex(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' rename index '.$this->wrap($command->string('from')->value()).' to '.$this->wrap($command->string('to')->value());
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileIndexVisibility(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' alter index '.$this->wrap($command->string('index')->value()).($command->boolean('visible') ? ' visible' : ' invisible');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    public function compileTableComment(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint)." comment = '".str_replace("'", "''", $command->string('comment')->value())."'";
    }

    /**
     * @param  array<string>  $tables
     */
    public function compileDropAllTables(array $tables): string
    {
        return 'drop table '.implode(', ', $this->escapeNames($tables));
    }

    /**
     * @param  array<string>  $views
     */
    public function compileDropAllViews(array $views): string
    {
        return 'drop view '.implode(', ', $this->escapeNames($views));
    }

    /**
     * @param  list<string>  $sequences
     */
    public function compileDropAllSequences(array $sequences): string
    {
        return 'drop sequence '.implode(', ', $this->escapeNames($sequences));
    }

    public function compileEnableForeignKeyConstraints(): string
    {
        return 'SET FOREIGN_KEY_CHECKS=1;';
    }

    public function compileDisableForeignKeyConstraints(): string
    {
        return 'SET FOREIGN_KEY_CHECKS=0;';
    }

    /**
     * @param  array<string>  $names
     * @return array<string>
     */
    public function escapeNames($names)
    {
        return array_map(fn (string $name): string => (new Stringable($name))->explode('.')->map($this->wrapValue(...))->implode('.'), $names);
    }

    protected function wrapValue($value)
    {
        return $value === '*' ? $value : '`'.str_replace('`', '``', $value).'`';
    }

    protected function wrapJsonSelector($value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return 'json_unquote(json_extract('.$field.$path.'))';
    }

    /**
     * A table holding an AUTO_RANDOM column needs a clustered primary key; primary(...)->clustered(false) asks for NONCLUSTERED.
     *
     * @param  Fluent<string, mixed>  $command
     */
    private function compilePrimaryDefinition(Blueprint $blueprint, Fluent $command): string
    {
        $this->ensureBtree($command);

        $autoRandom = array_any($blueprint->getAddedColumns(), fn (ColumnDefinition $column): bool => (bool) $column->get('autoRandom'));

        if ($autoRandom && $command->get('clustered') === false) {
            throw new InvalidArgumentException('A table with an AUTO_RANDOM column needs a clustered primary key.');
        }

        return 'primary key ('.$this->columnizeIndex($command->array('columns')).')'
            .match ($autoRandom ?: $command->get('clustered')) {
                true => ' clustered',
                false => ' nonclustered',
                default => '',
            }
        .$this->compileIndexComment($command);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    private function compileIndexDefinition(Fluent $command, string $type): string
    {
        $this->ensureBtree($command);

        return $type.' '.$this->wrap($command->string('index')->value()).' ('.$this->columnizeIndex($command->array('columns')).')'
            .$this->compileIndexComment($command)
            .($command->boolean('invisible') ? ' invisible' : '')
            .($command->boolean('global') ? ' global' : '');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    private function compileIndexComment(Fluent $command): string
    {
        return $command->get('comment') === null ? '' : " comment '".str_replace("'", "''", $command->string('comment')->value())."'";
    }

    /**
     * @param  array<array-key, mixed>  $columns
     */
    private function columnizeIndex(array $columns): string
    {
        return implode(', ', array_map(fn (mixed $column): string => match (true) {
            $column instanceof Expression => '('.$this->getValue($column).')',
            is_string($column) => $this->wrap($column),
            default => throw new InvalidArgumentException('Index columns must be names or expressions.'),
        }, $columns));
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    private function ensureBtree(Fluent $command): void
    {
        $algorithm = $command->get('algorithm');

        if ($algorithm !== null && strtolower($command->string('algorithm')->value()) !== 'btree') {
            throw UnsupportedFeatureException::because("the [{$command->string('algorithm')}] index algorithm", 'TiDB indexes are ordered key ranges.');
        }
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    private function compileAlgorithmAndLock(Fluent $command, string $algorithm): string
    {
        return ($command->boolean($algorithm) ? ', algorithm='.$algorithm : '').($command->filled('lock') ? ', lock='.$command->string('lock') : '');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    private function commandColumn(Fluent $command): ColumnDefinition
    {
        $column = $command->get('column');

        return $column instanceof ColumnDefinition ? $column : throw new InvalidArgumentException('The command has no column definition.');
    }
}
