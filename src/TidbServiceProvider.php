<?php

namespace StringKe\TidbPhp\Laravel;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Console\DbCommand;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Support\ServiceProvider;
use PDO;
use StringKe\TidbPhp\Laravel\Console\TidbDbCommand;

class TidbServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('db.connector.tidb', TidbConnector::class);

        Connection::resolverFor('tidb', fn (PDO|Closure $pdo, string $database, string $prefix, array $config): TidbConnection => new TidbConnection($pdo, $database, $prefix, $config));

        $this->app->extend('migration.repository', function ($repository, $app) {
            if ($repository::class !== DatabaseMigrationRepository::class) {
                return $repository;
            }

            $migrations = $app['config']['database.migrations'];

            return new TidbMigrationRepository($app['db'], is_array($migrations) ? ($migrations['table'] ?? null) : $migrations);
        });

        $this->app->extend(DbCommand::class, fn ($command) => $command::class === DbCommand::class ? new TidbDbCommand : $command);

        EloquentBatchMacros::register();
    }
}
