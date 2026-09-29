<?php

namespace StringKe\TidbPhp\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use StringKe\TidbPhp\Laravel\TidbServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [TidbServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'tidb');
        $app['config']->set('database.connections.tidb', [
            'driver' => 'tidb',
            'host' => self::env('TIDB_LARAVEL_TEST_HOST', '127.0.0.1'),
            'port' => self::env('TIDB_LARAVEL_TEST_PORT', '4410'),
            'username' => self::env('TIDB_LARAVEL_TEST_USER', 'root'),
            'password' => self::env('TIDB_LARAVEL_TEST_PASSWORD', ''),
            'database' => 'test',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_bin',
            'prefix' => '',
            'strict' => true,
            'timezone' => '+00:00',
            'auto_random' => ['shard_bits' => 5, 'range_bits' => 54],
        ]);
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false ? $default : $value;
    }
}
