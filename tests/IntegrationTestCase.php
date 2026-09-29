<?php

namespace StringKe\TidbPhp\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StringKe\TidbPhp\Laravel\TidbConnection;
use Throwable;

/**
 * Every test gets its own database, dropped afterwards. Tests are skipped when the server cannot be reached.
 */
abstract class IntegrationTestCase extends TestCase
{
    public TidbConnection $tidb;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = 'tidb_laravel_test_'.Str::lower(Str::random(10));

        try {
            DB::connection('tidb')->statement("create database `{$this->database}`");
        } catch (Throwable $e) {
            $this->markTestSkipped('TiDB server not reachable: '.$e->getMessage());
        }

        config(['database.connections.tidb.database' => $this->database]);
        DB::purge('tidb');
        $connection = DB::connection('tidb');
        $this->assertInstanceOf(TidbConnection::class, $connection);
        $this->tidb = $connection;
    }

    protected function tearDown(): void
    {
        if (isset($this->tidb)) {
            $this->tidb->statement("drop database if exists `{$this->database}`");
        }

        parent::tearDown();
    }
}
