# Development

## Layout

| Path | Content |
|---|---|
| `src/` | connection, connector, grammars, schema builder, blueprint, processor, schema state, migration repository and service provider |
| `src/Concerns/` | column types, column modifiers, table options, schema queries and JSON compilation shared by the grammars |
| `src/Console/` | the `db` command for the `tidb` driver |
| `tests/Unit/` | tests that compile SQL or build DSNs without a server |
| `tests/Integration/` | tests against a real server, each in its own database |
| `compose.yaml` | local TiDB for the tests |
| `docs/` | documentation |

The `pdo_tidb` extension has to be loaded. Build it from [tidb-php](https://github.com/stringke/tidb-php).

## Local server

```sh
docker compose up -d --wait
```

This starts TiDB v8.5.8 with unistore on `127.0.0.1:4410`. `docker compose down -v` removes everything.

## Checks

```sh
composer install
composer lint
composer typecheck
composer test
```

`composer format` applies the Pint fixes.

## Tests

The integration tests read their connection from the environment and default to the compose setup:

| Variable | Default |
|---|---|
| `TIDB_LARAVEL_TEST_HOST` | `127.0.0.1` |
| `TIDB_LARAVEL_TEST_PORT` | `4410` |
| `TIDB_LARAVEL_TEST_USER` | `root` |
| `TIDB_LARAVEL_TEST_PASSWORD` | empty |

Each integration test creates a database named `tidb_laravel_test_` plus a random suffix and drops it afterwards. The test user needs `CREATE DATABASE`. The stale read tests wait about one second, because the read timestamp comes from the server clock.

Integration tests are skipped when the server cannot be reached. `vendor/bin/pest --testsuite=Unit` runs only the offline tests.

## Releases

1. Update `CHANGELOG.md`.
2. Run the checks against the compose server.
3. Tag `v<version>` and push the tag.

## Commits

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/) in English, for example `fix(schema): keep the index comment on expression indexes` or `docs: document table options`. Scopes in use: `connection`, `query`, `schema`, `tests`, `build`, `docs`.
