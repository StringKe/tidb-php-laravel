<?php

use Illuminate\Database\Console\DbCommand;
use Illuminate\Support\Facades\DB;
use StringKe\TidbPhp\Laravel\Console\TidbDbCommand;
use StringKe\TidbPhp\Laravel\TidbConnection;
use StringKe\TidbPhp\Laravel\TidbConnector;
use StringKe\TidbPhp\Laravel\TidbMigrationRepository;

test('the tidb driver resolves its own connection, repository and db command without contacting the server', function (): void {
    expect(DB::connection('tidb'))->toBeInstanceOf(TidbConnection::class)
        ->and(app('db.connector.tidb'))->toBeInstanceOf(TidbConnector::class)
        ->and(app('migration.repository'))->toBeInstanceOf(TidbMigrationRepository::class)
        ->and(app(DbCommand::class))->toBeInstanceOf(TidbDbCommand::class);
});

test('the DSN carries connection keys, session variables and attributes', function (): void {
    $dsn = (new TidbConnector)->dsn([
        'host' => 'h', 'port' => 4000, 'database' => 'd', 'charset' => 'utf8mb4', 'sslmode' => 'verify_identity', 'timezone' => '+00:00',
        'isolation_level' => 'read committed', 'strict' => true, 'settings' => ['tidb_mem_quota_query' => 1073741824, 'skipped' => null],
        'attributes' => ['app' => 'growth'], 'discover' => true, 'unknown_key' => 'x',
    ]);

    expect($dsn)->toBe('tidb:dbname=d;host=h;port=4000;charset=utf8mb4;sslmode=verify_identity;discover=1;time_zone=+00:00;'
        .'session.transaction_isolation=READ-COMMITTED;'
        .'session.sql_mode=ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION;'
        .'session.tidb_mem_quota_query=1073741824;attr.app=growth');
});

test('a unix socket replaces host and port, and values that would break the DSN are rejected', function (): void {
    $connector = new TidbConnector;

    expect($connector->dsn(['host' => 'h', 'port' => 4000, 'unix_socket' => '/tmp/tidb.sock', 'database' => 'd']))->toBe('tidb:dbname=d;unix_socket=/tmp/tidb.sock')
        ->and(fn () => $connector->dsn(['host' => 'h', 'settings' => ['a' => 'x;y']]))->toThrow(LogicException::class, '[session.a]')
        ->and(fn () => $connector->dsn(['host' => 'h', 'settings' => ['a-b' => '1']]))->toThrow(LogicException::class, '[session.a-b]')
        ->and(fn () => $connector->dsn(['host' => 'h', 'attributes' => ['a' => ['x']]]))->toThrow(LogicException::class, '[attr.a]');
});

test('the db command opens the mysql client with the password in the environment and comments kept', function (): void {
    $command = new TidbDbCommand;
    $connection = ['driver' => 'tidb', 'host' => 'h', 'port' => 4000, 'username' => 'u', 'password' => 'secret', 'database' => 'd', 'charset' => 'utf8mb4'];

    expect($command->getCommand($connection))->toBe('mysql')
        ->and($command->commandArguments($connection))->toBe(['--host=h', '--port=4000', '--user=u', '--comments', '--default-character-set=utf8mb4', 'd'])
        ->and($command->commandEnvironment($connection))->toBe(['MYSQL_PWD' => 'secret']);
});
