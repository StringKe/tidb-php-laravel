<?php

namespace StringKe\TidbPhp\Laravel;

use Closure;
use DateTimeInterface;
use Exception;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use LogicException;
use Pdo\Tidb;
use PDOException;
use Throwable;

/**
 * Driver operations run through run(), so they are logged, dispatch QueryExecuted and fail with QueryException like any SQL statement.
 */
class TidbConnection extends Connection
{
    private const array RETRYABLE_CLASSES = ['retry_transaction', 'backoff'];

    /**
     * @var string|int|null
     */
    protected $lastInsertId;

    public function getDriverTitle()
    {
        return 'TiDB';
    }

    /**
     * @param  array<mixed>  $bindings
     * @param  string|null  $sequence
     */
    public function insert($query, $bindings = [], $sequence = null)
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($sequence) {
            if ($this->pretending()) {
                return true;
            }

            $statement = $this->getPdo()->prepare($query);

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $this->recordsHaveBeenModified();

            $result = $statement->execute();

            $id = $this->getPdo()->lastInsertId($sequence);
            $this->lastInsertId = $id === false ? null : $id;

            return $result;
        });
    }

    /**
     * @return string|int|null
     */
    public function getLastInsertId()
    {
        return $this->lastInsertId;
    }

    /**
     * @return TidbQueryBuilder
     */
    public function query()
    {
        return new TidbQueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    protected function getDefaultQueryGrammar()
    {
        return new TidbQueryGrammar($this);
    }

    protected function getDefaultSchemaGrammar()
    {
        return new TidbSchemaGrammar($this);
    }

    /**
     * @return TidbSchemaBuilder
     */
    public function getSchemaBuilder()
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new TidbSchemaBuilder($this);
    }

    /**
     * @return TidbSchemaState
     */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null)
    {
        return new TidbSchemaState($this, $files, $processFactory);
    }

    protected function getDefaultPostProcessor()
    {
        return new TidbProcessor;
    }

    protected function escapeBinary($value)
    {
        return "x'".bin2hex($value)."'";
    }

    protected function isUniqueConstraintError(Exception $exception)
    {
        return $this->errorCode($exception) === 1062;
    }

    /**
     * @return array{index: string|null, columns: list<string>}
     */
    protected function parseUniqueConstraintViolation(Exception $exception): array
    {
        preg_match("#Duplicate entry '.*' for key '(?:[^'.]*\\.)?([^']+)'#s", $exception->getMessage(), $matches);

        return ['columns' => [], 'index' => $matches[1] ?? null];
    }

    /**
     * TiDB reports write conflicts, schema changes during commit and region errors with its own codes instead of SQLSTATE 40001.
     */
    protected function causedByConcurrencyError(Throwable $e)
    {
        if (parent::causedByConcurrencyError($e)) {
            return true;
        }

        $code = $this->errorCode($e);

        return $code !== null && in_array(Tidb::errorClass($code), self::RETRYABLE_CLASSES, true);
    }

    protected function causedByLostConnection(Throwable $e)
    {
        if (parent::causedByLostConnection($e)) {
            return true;
        }

        $code = $this->errorCode($e);

        return $code !== null && Tidb::errorClass($code) === 'reconnect';
    }

    public function tidb(): Tidb
    {
        $pdo = $this->getPdo();

        if (! $pdo instanceof Tidb) {
            throw new LogicException('The connection is not a Pdo\Tidb instance.');
        }

        return $pdo;
    }

    /**
     * Runs the callback in a read-only transaction that reads the data as it was at the given time.
     *
     * @template TReturn
     *
     * @param  Closure(static): TReturn  $callback
     * @return TReturn
     */
    public function staleRead(DateTimeInterface|Expression $at, Closure $callback): mixed
    {
        if ($this->transactionLevel() > 0) {
            throw new LogicException('A stale read cannot start inside a transaction.');
        }

        $this->executeRaw('start transaction read only as of timestamp '.$this->getQueryGrammar()->compileTimestamp($at));

        try {
            $result = $callback($this);
        } catch (Throwable $e) {
            $this->unprepared('rollback');

            throw $e;
        }

        $this->unprepared('commit');

        return $result;
    }

    /**
     * Runs a statement over the text protocol like unprepared(), for SQL assembled by the driver itself.
     */
    public function executeRaw(string $sql): bool
    {
        return $this->run($sql, [], function ($sql): bool {
            if ($this->pretending()) {
                return true;
            }

            $this->recordsHaveBeenModified($changed = $this->getPdo()->exec($sql) !== false);

            return $changed;
        });
    }

    /**
     * @return TidbQueryGrammar
     */
    public function getQueryGrammar()
    {
        $grammar = parent::getQueryGrammar();

        if (! $grammar instanceof TidbQueryGrammar) {
            throw new LogicException('The tidb connection needs its own query grammar.');
        }

        return $grammar;
    }

    public function ping(): bool
    {
        return $this->run('/* ping */', [], fn (): bool => $this->pretending() || $this->tidb()->ping());
    }

    /**
     * Drops user variables, session variables, temporary tables and prepared statements, then applies the configured session settings again.
     */
    public function resetSession(): void
    {
        $this->run('/* reset connection */', [], fn (): bool => $this->pretending() || $this->tidb()->resetConnection());
    }

    public function useDatabase(string $database): void
    {
        $this->run("use `{$database}`", [], fn (): bool => $this->pretending() || $this->tidb()->selectDatabase($database));
        $this->setDatabaseName($database);
    }

    public function connectionId(): int
    {
        return $this->tidb()->connectionId();
    }

    public function killRunningQuery(): bool
    {
        return $this->tidb()->killQuery();
    }

    /**
     * Loads the given rows into the table with LOAD DATA LOCAL INFILE and returns the affected row count.
     */
    public function loadData(string $sql, string $data): int
    {
        return $this->run($sql, [], function () use ($sql, $data): int {
            if ($this->pretending()) {
                return 0;
            }

            $this->recordsHaveBeenModified();

            $count = $this->tidb()->loadDataLocal($sql, $data);

            if ($count === false) {
                throw new LogicException('LOAD DATA LOCAL failed.');
            }

            return $count;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function connectionInfo(): array
    {
        return $this->tidb()->connectionInfo();
    }

    public function warningCount(): int
    {
        return $this->tidb()->warningCount();
    }

    public function lastInfo(): ?string
    {
        return $this->tidb()->lastInfo();
    }

    private function errorCode(Throwable $e): ?int
    {
        $previous = $e;

        while ($previous !== null && ! $previous instanceof PDOException) {
            $previous = $previous->getPrevious();
        }

        $code = $previous?->errorInfo[1] ?? null;

        return is_numeric($code) ? (int) $code : null;
    }
}
