<?php

use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Support\Facades\DB;
use StringKe\TidbPhp\Laravel\TidbBlueprint;
use StringKe\TidbPhp\Laravel\TidbSchemaBuilder;

function tidbSchema(): TidbSchemaBuilder
{
    $schema = test()->tidb->getSchemaBuilder();
    expect($schema)->toBeInstanceOf(TidbSchemaBuilder::class);

    return $schema;
}

function tidbRepository(): MigrationRepositoryInterface
{
    $repository = app('migration.repository');
    expect($repository)->toBeInstanceOf(MigrationRepositoryInterface::class);

    return $repository;
}

function createOrders(): void
{
    tidbSchema()->create('orders', function (TidbBlueprint $table): void {
        $table->autoRandom()->comment('订单身份');
        $table->string('no', 20)->unique('orders_no_unique')->comment('订单号');
        $table->string('email')->default('');
        $table->datetime('created_at', 6)->useCurrent();
        $table->integer('total')->storedAs('length(`no`)');
        $table->index([DB::raw('lower(email)')], 'orders_email_index')->invisible()->comment('按邮箱找订单');
        $table->ttl('created_at', '30 day', false);
        $table->comment('订单');
    });
}

test('metadata reports AUTO_RANDOM, the clustered key, generated columns and index properties', function (): void {
    createOrders();

    $columns = collect(tidbSchema()->getColumns('orders'))->keyBy('name');
    $indexes = collect(tidbSchema()->getIndexes('orders'))->keyBy('name');
    $table = collect(tidbSchema()->getTables(test()->tidb->getDatabaseName()))->firstWhere('name', 'orders');

    expect($columns['id']['auto_random'])->toBeTrue()
        ->and($columns['no']['auto_random'])->toBeFalse()
        ->and($columns['id']['comment'])->toBe('订单身份')
        ->and($columns['total']['generation'])->toBe(['type' => 'stored', 'expression' => 'length(`no`)'])
        ->and($indexes['primary'])->toMatchArray(['columns' => ['id'], 'primary' => true, 'clustered' => true, 'unique' => true])
        ->and($indexes['orders_no_unique'])->toMatchArray(['columns' => ['no'], 'unique' => true, 'clustered' => false, 'visible' => true])
        ->and($indexes['orders_email_index'])->toMatchArray(['columns' => [], 'expressions' => ['lower(`email`)'], 'visible' => false, 'comment' => '按邮箱找订单'])
        ->and($table)->toMatchArray(['clustered' => true, 'comment' => '订单'])
        ->and($table['row_id_sharding'])->toStartWith('PK_AUTO_RANDOM_BITS=5');
});

test('table options change on an existing table and TTL can be removed', function (): void {
    createOrders();

    tidbSchema()->table('orders', function (TidbBlueprint $table): void {
        $table->ttl('created_at', '7 day', true, '2h');
        $table->autoIdCache(100);
        $table->makeIndexVisible('orders_email_index');
    });
    $create = (string) array_values((array) test()->tidb->selectOne('show create table `orders`'))[1];

    expect($create)->toContain('TTL=`created_at` + INTERVAL 7 DAY')->toContain("TTL_JOB_INTERVAL='2h'")->toContain('AUTO_ID_CACHE=100')
        ->and(collect(tidbSchema()->getIndexes('orders'))->firstWhere('name', 'orders_email_index')['visible'])->toBeTrue();

    tidbSchema()->table('orders', fn (TidbBlueprint $table) => $table->removeTtl());

    expect((string) array_values((array) test()->tidb->selectOne('show create table `orders`'))[1])->not->toContain('TTL=');
});

test('row id sharding and pre-split regions apply to a table without a clustered key', function (): void {
    tidbSchema()->create('events', function (TidbBlueprint $table): void {
        $table->bigInteger('id');
        $table->primary('id')->clustered(false);
        $table->shardRowIdBits(4);
        $table->preSplitRegions(2);
    });

    expect(collect(tidbSchema()->getTables(test()->tidb->getDatabaseName()))->firstWhere('name', 'events'))->toMatchArray(['clustered' => false, 'row_id_sharding' => 'SHARD_BITS=4']);
});

test('the AUTO_RANDOM shard bits of an existing key can be raised', function (): void {
    createOrders();
    test()->tidb->table('orders')->insert(['no' => 'a']);

    tidbSchema()->table('orders', fn (TidbBlueprint $table) => $table->bigInteger('id')->autoRandom(6, 54)->change());

    expect(collect(tidbSchema()->getTables(test()->tidb->getDatabaseName()))->firstWhere('name', 'orders')['row_id_sharding'])->toStartWith('PK_AUTO_RANDOM_BITS=6')
        ->and(test()->tidb->table('orders')->insertGetId(['no' => 'b']))->toBeGreaterThan(0);
});

test('dropping everything removes tables, views and sequences', function (): void {
    createOrders();
    test()->tidb->statement('create view `recent_orders` as select `id` from `orders`');
    test()->tidb->statement('create sequence `order_no_seq`');

    tidbSchema()->dropAllViews();
    tidbSchema()->dropAllTables();

    expect(tidbSchema()->getTables(test()->tidb->getDatabaseName()))->toBe([])
        ->and(tidbSchema()->getViews(test()->tidb->getDatabaseName()))->toBe([])
        ->and(tidbSchema()->getSequences(test()->tidb->getDatabaseName()))->toBe([]);
});

test('the migration repository keys its table with AUTO_RANDOM', function (): void {
    $repository = tidbRepository();
    $repository->setSource('tidb');
    $repository->createRepository();
    $repository->log('2026_09_29_000000_create_orders', 1);

    expect($repository->getRan())->toBe(['2026_09_29_000000_create_orders'])
        ->and(collect(tidbSchema()->getColumns('migrations'))->firstWhere('name', 'id')['auto_random'])->toBeTrue();
});

test('a schema dump loads back into an empty database with the same definitions and migration rows', function (): void {
    $repository = tidbRepository();
    $repository->setSource('tidb');
    $repository->createRepository();
    $repository->log('2026_09_29_000000_create_orders', 1);
    createOrders();
    test()->tidb->table('orders')->insert(['no' => 'a']);
    test()->tidb->statement('create view `order_numbers` as select `no` from `orders`');
    test()->tidb->statement('create view `order_numbers_upper` as select upper(`no`) as `no` from `order_numbers`');
    test()->tidb->statement('create sequence `order_no_seq`');

    $path = sys_get_temp_dir().'/tidb-laravel-dump-'.bin2hex(random_bytes(4)).'.sql';
    $before = collect(['orders', 'migrations'])->mapWithKeys(fn (string $table) => [$table => tidbSchema()->getColumns($table)]);

    try {
        test()->tidb->getSchemaState()->dump(test()->tidb, $path);

        tidbSchema()->dropAllViews();
        tidbSchema()->dropAllTables();
        test()->tidb->getSchemaState()->load($path);
    } finally {
        @unlink($path);
    }

    expect(collect(['orders', 'migrations'])->mapWithKeys(fn (string $table) => [$table => tidbSchema()->getColumns($table)]))->toEqual($before)
        ->and(collect(tidbSchema()->getViews(test()->tidb->getDatabaseName()))->pluck('name')->sort()->values()->all())->toBe(['order_numbers', 'order_numbers_upper'])
        ->and(collect(tidbSchema()->getSequences(test()->tidb->getDatabaseName()))->pluck('name')->all())->toBe(['order_no_seq'])
        ->and(test()->tidb->table('orders')->count())->toBe(0)
        ->and($repository->getRan())->toBe(['2026_09_29_000000_create_orders']);
});
