<?php

use Illuminate\Support\Facades\DB;
use StringKe\TidbPhp\Laravel\TidbBlueprint;
use StringKe\TidbPhp\Laravel\TidbConnection;
use StringKe\TidbPhp\Laravel\TidbIndexDefinition;
use StringKe\TidbPhp\Laravel\UnsupportedFeatureException;

/**
 * @param  Closure(TidbBlueprint): void  $callback
 * @return list<string>
 */
function tidbSql(Closure $callback, bool $create = false): array
{
    $connection = DB::connection('tidb');
    expect($connection)->toBeInstanceOf(TidbConnection::class);
    $connection->useDefaultSchemaGrammar();

    $blueprint = new TidbBlueprint($connection, 'orders');

    if ($create) {
        $blueprint->create();
    }

    $callback($blueprint);

    return $blueprint->toSql();
}

test('an AUTO_RANDOM key takes the connection bits and a clustered primary key, indexes and options stay in CREATE', function (): void {
    $sql = tidbSql(function (TidbBlueprint $table): void {
        $table->autoRandom()->comment('订单身份');
        $table->string('no', 20)->unique()->comment('订单号');
        $table->index(['created_at'], 'orders_created_at_index')->comment('按下单时刻翻页');
        $table->datetime('created_at', 6)->useCurrent();
        $table->date('biz_date')->useCurrent();
        $table->ttl('created_at', '90 day', true, '1h');
        $table->autoRandomBase(1000);
        $table->placementPolicy('p1');
        $table->comment('订单');
    }, create: true);

    expect($sql)->toBe([
        'create table `orders` (`id` bigint not null auto_random(5, 54) comment \'订单身份\', `no` varchar(20) not null comment \'订单号\', '
        .'`created_at` datetime(6) not null default CURRENT_TIMESTAMP(6), `biz_date` date not null default (CURRENT_DATE()), '
        .'primary key (`id`) clustered, index `orders_created_at_index` (`created_at`) comment \'按下单时刻翻页\', unique key `orders_no_unique` (`no`)) '
        ."default character set utf8mb4 collate 'utf8mb4_bin' ttl = `created_at` + interval 90 day ttl_enable = 'ON' ttl_job_interval = '1h' auto_random_base = 1000 placement policy = `p1`",
        "alter table `orders` comment = '订单'",
    ]);
});

test('AUTO_RANDOM bits come from the column, then the connection config', function (): void {
    expect(tidbSql(fn (TidbBlueprint $table) => $table->bigInteger('id')->autoRandom(6), create: true))
        ->toBe(["create table `orders` (`id` bigint not null auto_random(6) primary key clustered) default character set utf8mb4 collate 'utf8mb4_bin'"])
        ->and(tidbSql(fn (TidbBlueprint $table) => $table->bigInteger('id')->autoRandom(4, 50)->change()))
        ->toBe(['alter table `orders` modify `id` bigint not null auto_random(4, 50)'])
        ->and(fn () => tidbSql(fn (TidbBlueprint $table) => $table->autoRandom()->default(1), create: true))->toThrow(InvalidArgumentException::class)
        ->and(fn () => tidbSql(function (TidbBlueprint $table): void {
            $table->autoRandom();
            $table->primary('id')->clustered(false);
        }, create: true))->toThrow(InvalidArgumentException::class);
});

test('a non-clustered key with row id sharding and pre-split regions', function (): void {
    expect(tidbSql(function (TidbBlueprint $table): void {
        $table->bigInteger('id');
        $table->primary('id')->clustered(false);
        $table->shardRowIdBits(4);
        $table->preSplitRegions(2);
    }, create: true))->toBe(["create table `orders` (`id` bigint not null, primary key (`id`) nonclustered) default character set utf8mb4 collate 'utf8mb4_bin' shard_row_id_bits = 4 pre_split_regions = 2"]);
});

test('table options on an existing table compile as ALTER, TiFlash replicas and visibility included', function (): void {
    expect(tidbSql(function (TidbBlueprint $table): void {
        $table->shardRowIdBits(3);
        $table->autoIdCache(100);
        $table->ttlEnable(false);
        $table->removeTtl();
        $table->placementPolicy(null);
        $table->tiflashReplica(1, ['zone']);
        $table->makeIndexInvisible('orders_a_index');
        $table->index([DB::raw('lower(email)')], 'orders_email_index')->invisible();
    }))->toBe([
        'alter table `orders` shard_row_id_bits = 3',
        'alter table `orders` auto_id_cache = 100',
        "alter table `orders` ttl_enable = 'OFF'",
        'alter table `orders` remove ttl',
        'alter table `orders` placement policy = default',
        "alter table `orders` set tiflash replica 1 location labels 'zone'",
        'alter table `orders` alter index `orders_a_index` invisible',
        'alter table `orders` add index `orders_email_index` ((lower(email))) invisible',
    ])->and(fn () => tidbSql(fn (TidbBlueprint $table) => $table->preSplitRegions(1)))->toThrow(UnsupportedFeatureException::class);
});

test('index commands return TiDB index definitions whose modifiers compile on an existing table', function (): void {
    $sql = tidbSql(function (TidbBlueprint $table): void {
        expect($table->primary('id')->clustered(false)->comment('订单身份'))->toBeInstanceOf(TidbIndexDefinition::class)
            ->and($table->unique('no')->comment('订单号')->global()->lock('none'))->toBeInstanceOf(TidbIndexDefinition::class)
            ->and($table->index('created_at')->comment("按'下单'时刻")->invisible()->inplace())->toBeInstanceOf(TidbIndexDefinition::class)
            ->and($table->rawIndex('lower(email)', 'orders_email_index')->comment('邮箱'))->toBeInstanceOf(TidbIndexDefinition::class);
    });

    expect($sql)->toBe([
        "alter table `orders` add primary key (`id`) nonclustered comment '订单身份'",
        "alter table `orders` add unique key `orders_no_unique` (`no`) comment '订单号' global, lock=none",
        "alter table `orders` add index `orders_created_at_index` (`created_at`) comment '按''下单''时刻' invisible, algorithm=inplace",
        "alter table `orders` add index `orders_email_index` ((lower(email))) comment '邮箱'",
    ]);
});

test('vector columns get an HNSW index over the chosen distance', function (): void {
    expect(tidbSql(function (TidbBlueprint $table): void {
        $table->vector('embedding', 3);
        $table->vectorIndex('embedding');
        $table->vectorIndex('embedding', 'orders_embedding_l2')->operatorClass('l2');
    }))->toBe([
        'alter table `orders` add `embedding` vector(3) not null',
        'alter table `orders` add vector index `orders_embedding_vectorindex` ((vec_cosine_distance(`embedding`))) using hnsw',
        'alter table `orders` add vector index `orders_embedding_l2` ((vec_l2_distance(`embedding`))) using hnsw',
    ]);
});

test('features TiDB accepts but ignores or cannot parse are rejected', function (Closure $callback): void {
    expect(fn () => tidbSql($callback))->toThrow(UnsupportedFeatureException::class);
})->with([
    'full-text index' => fn (TidbBlueprint $table) => $table->fullText('body'),
    'spatial index' => fn (TidbBlueprint $table) => $table->spatialIndex('location'),
    'geometry column' => fn (TidbBlueprint $table) => $table->geometry('location'),
    'invisible column' => fn (TidbBlueprint $table) => $table->string('a')->invisible(),
    'current year default' => fn (TidbBlueprint $table) => $table->year('y')->useCurrent(),
    'stored column added later' => fn (TidbBlueprint $table) => $table->string('a')->storedAs('upper(b)'),
    'hash index' => fn (TidbBlueprint $table) => $table->index('a', null, 'hash'),
]);
