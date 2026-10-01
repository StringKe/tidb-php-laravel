# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [0.1.1] - 2026-10-01

### Added

- `TidbIndexDefinition`, returned by `primary()`, `unique()`, `index()`, `rawIndex()` and `vectorIndex()` on `TidbBlueprint`, with typed `comment()`, `invisible()`, `global()` and `clustered()` modifiers.

## [0.1.0] - 2026-09-29

First release.

### Added

- `tidb` database driver for Laravel 13 on top of `pdo_tidb`, registered through package discovery, without extending the Laravel MySQL classes.
- Connector that writes connection keys, session variables and connection attributes into the DSN, so they survive reconnects and session resets.
- Query grammar with optimizer hints, `MAX_EXECUTION_TIME`, stale reads through `asOf()`, non-transactional batch DML, case-sensitive and case-insensitive `LIKE`, JSON paths and functions, `upsert()` through `values()`, vector distances and the row locks TiDB takes.
- Schema grammar, builder and blueprint with `AUTO_RANDOM` keys, clustered and non-clustered primary keys, expression, invisible and global indexes, table options (`SHARD_ROW_ID_BITS`, `PRE_SPLIT_REGIONS`, `AUTO_ID_CACHE`, `AUTO_RANDOM_BASE`, TTL, placement policy), TiFlash replicas, vector columns and vector indexes.
- Introspection of tables, views, sequences, columns, indexes and foreign keys with the TiDB specific properties.
- Schema dump and load over the driver connection, an `AUTO_RANDOM` migration repository and the `db` command.
- `TidbConnection` methods for stale reads, `LOAD DATA LOCAL`, session control, query control and connection info, all running through Laravel's query pipeline. TiDB write conflicts are retried as concurrency errors.
- Compile-time `UnsupportedFeatureException` for every feature TiDB ignores silently or rejects.

[0.1.1]: https://github.com/stringke/tidb-php-laravel/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/stringke/tidb-php-laravel/releases/tag/v0.1.0
