# tidb-php-laravel

Laravel database driver for [TiDB](https://www.pingcap.com/), built on the [`pdo_tidb`](https://github.com/stringke/tidb-php) extension. It adds a `tidb` connection driver with its own connection, connector, query grammar, schema grammar, schema builder, blueprint, post processor, schema state and migration repository. None of them extends the Laravel MySQL classes: TiDB speaks the MySQL protocol, but its SQL, DDL and metadata differ, and the driver compiles for TiDB only.

```php
// config/database.php
'tidb' => [
    'driver' => 'tidb',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', 4000),
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_bin',
    'timezone' => '+00:00',
    'strict' => true,
    'auto_random' => ['shard_bits' => 5, 'range_bits' => 54],
],
```

```php
Schema::create('orders', function (TidbBlueprint $table) {
    $table->autoRandom();
    $table->string('no', 20)->unique();
    $table->ttl('created_at', '90 day');
});

DB::table('orders')->hint('USE_INDEX(orders, orders_no_unique)')->asOf(now()->subSeconds(5))->where('no', $no)->first();
```

## Requirements

- PHP 8.4 or newer with the `pdo_tidb` extension loaded. Development and the test suite run on PHP 8.5.
- Laravel 13 (`illuminate/database`, `illuminate/support` and `illuminate/console`).
- TiDB 8.5. The test suite runs against v8.5.8.

## Install

```sh
composer require stringke/tidb-php-laravel
```

The service provider is registered through package discovery.

## Configuration

| Key | Meaning |
|---|---|
| `host`, `port`, `unix_socket`, `database`, `username`, `password` | connection target and credentials; a socket replaces host and port |
| `charset`, `collation`, `timezone` | connection character set, collation and session time zone |
| `strict`, `modes`, `isolation_level` | `sql_mode` and `transaction_isolation` of the session |
| `settings` | further session variables, for example `tidb_mem_quota_query` |
| `attributes` | connection attributes shown in `information_schema.processlist` |
| `sslmode`, `ssl_*`, `tls_version`, `compression`, `zstd_level`, timeouts, `discover`, `max_lifetime`, `idle_ping` and the other `pdo_tidb` keys | written into the DSN as is |
| `auto_random` | default `shard_bits` and `range_bits` for `autoRandom()` columns that give none |
| `options` | PDO options |

Session variables travel in the DSN, so `pdo_tidb` applies them again after a reconnect and after `resetSession()`. Values containing `;`, non-scalar values and names outside `[A-Za-z0-9_.]` are rejected, because they would change the meaning of the DSN.

## Queries

- `hint(...)` places optimizer hints after `SELECT`, `UPDATE` and `DELETE`, and `timeout($seconds)` adds `MAX_EXECUTION_TIME` to the same comment. `forceIndex()`, `useIndex()`, `ignoreIndex()` and `straightJoin()` compile as TiDB writes them.
- `asOf($time)` reads the table and every joined table at the same time (stale read). Writes on such a builder throw. `TidbConnection::staleRead($time, $callback)` runs a whole callback in a read-only transaction at that time.
- `batchUpdate()`, `batchDelete()` and `batchInsertUsing()` run non-transactional DML (`BATCH ON <column> LIMIT <size>`) and return the job summary, or the split statements with `dryRun`. The same methods exist as Eloquent builder macros; `batchUpdate()` there also sets `updated_at`.
- `whereLike()` is case-insensitive through `lower()` unless `caseSensitive` is set, which compiles to `like binary`. It behaves the same under `utf8mb4_bin` and the case-insensitive collations.
- JSON paths compile to `json_extract()` and `json_unquote()`. `whereJsonContains()`, `whereJsonOverlaps()`, `whereJsonContainsKey()`, `whereJsonLength()` and in-place JSON path updates use the TiDB JSON functions.
- `upsert()` updates from `values()`, because TiDB does not bind the row alias.
- `selectVectorDistance()` and the other vector distance helpers use `vec_cosine_distance()`.
- `lockForUpdate()` and `lock('for update nowait' | 'for update wait n' | 'for update of ...')` compile as given.
- `insertGetId()` returns the generated `AUTO_RANDOM` or `AUTO_INCREMENT` value.

## Schema

`Schema::create()` and `Schema::table()` pass a `TidbBlueprint`. `bigInteger()` returns a `TidbColumnDefinition`, and `primary()`, `unique()`, `index()`, `rawIndex()` and `vectorIndex()` return a `TidbIndexDefinition`, so static analysis sees the TiDB modifiers when the closure parameter is typed `TidbBlueprint`.

- `autoRandom($column = 'id', $shardBits, $rangeBits)` adds a clustered `AUTO_RANDOM` primary key; `bigInteger('id')->autoRandom(...)` does the same for an existing column definition and with `change()` raises the shard bits of an existing key.
- Primary keys take `clustered()` or `clustered(false)`. Primary keys, unique keys and indexes created with the table are written into `CREATE TABLE`. Indexes accept expressions (`index([DB::raw('lower(email)')])`), `comment()`, `invisible()` and `global()`, and `makeIndexVisible()` and `makeIndexInvisible()` switch visibility later.
- Table options: `shardRowIdBits()`, `preSplitRegions()` (create only), `autoIdCache()`, `autoRandomBase()`, `ttl()`, `ttlEnable()`, `ttlJobInterval()`, `removeTtl()`, `placementPolicy()` and `tiflashReplica()`. Given with `create()` they are part of `CREATE TABLE`; otherwise they compile to `ALTER TABLE`.
- `vector($column, $dimensions)` and `vectorIndex($column)` with the cosine or L2 distance; vector indexes need TiFlash.
- Generated columns, `useCurrent()` on `datetime`, `timestamp` and `date` columns, `useCurrentOnUpdate()`, column comments, `after()` and `first()`.
- `getTables()` reports `clustered` and `row_id_sharding`, `getColumns()` reports `auto_random`, and `getIndexes()` reports `clustered`, `visible`, `global`, `expressions` and `comment`. `getSequences()`, `dropAllTables()`, `dropAllViews()` and `dropAllSequences()` cover sequences as well.
- `schema:dump` writes the dump from `SHOW CREATE` over the driver connection: sequences, tables without `AUTO_INCREMENT` and `AUTO_RANDOM_BASE` counters, views in dependency order without `DEFINER`, and the migration rows. No `mysqldump` binary is needed, and `schema:load` reads it back the same way.
- The migration repository keys its table with `AUTO_RANDOM`.
- `php artisan db` opens the `mysql` client with the password in `MYSQL_PWD` and `--comments`, so optimizer hints reach the server.

## Driver features

`TidbConnection` runs every driver operation through `run()`. Driver operations are therefore logged, dispatch `QueryExecuted`, fail with `QueryException` and are skipped under `pretend()`.

| Method | Purpose |
|---|---|
| `tidb()` | the underlying `Pdo\Tidb` |
| `staleRead()` | read-only transaction at a past time |
| `executeRaw()` | text protocol statement for SQL the application assembles itself |
| `loadData()` | `LOAD DATA LOCAL INFILE` from a string |
| `ping()`, `resetSession()`, `useDatabase()` | session control |
| `connectionId()`, `killRunningQuery()`, `connectionInfo()`, `warningCount()`, `lastInfo()` | query control and connection info |

Duplicate keys raise `UniqueConstraintViolationException` with the index name. TiDB write conflicts, schema changes during commit and region errors count as concurrency errors, so `DB::transaction($callback, $attempts)` retries them.

## Not supported

TiDB accepts some MySQL syntax and ignores it, or rejects it at run time. The driver throws `UnsupportedFeatureException` while compiling instead, so nothing is skipped silently.

- Full-text and spatial indexes, `whereFullText()`, geometry and geography columns.
- `LOCK IN SHARE MODE` (`sharedLock()`) and `SKIP LOCKED`.
- `DELETE` with joins combined with `ORDER BY` or `LIMIT`.
- Invisible columns, `YEAR` columns defaulting to the current year, and stored generated columns added to an existing table.
- Hash indexes; every index is a B-tree.
- `preSplitRegions()` on an existing table.

## Development

See [docs/development.md](docs/development.md).

## License

Apache License 2.0, see [LICENSE](LICENSE).
