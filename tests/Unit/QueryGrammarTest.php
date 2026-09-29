<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use StringKe\TidbPhp\Laravel\TidbQueryBuilder;
use StringKe\TidbPhp\Laravel\UnsupportedFeatureException;

function tidbQuery(string $table = 'orders'): TidbQueryBuilder
{
    $query = DB::connection('tidb')->table($table);
    expect($query)->toBeInstanceOf(TidbQueryBuilder::class);

    return $query;
}

test('optimizer hints and the query timeout go into one comment after SELECT, UPDATE and DELETE', function (): void {
    $update = tidbQuery()->hint('MEMORY_QUOTA(1 GB)')->where('id', 1)->orderBy('created_at')->limit(5);
    $delete = tidbQuery()->hint('NO_INDEX_MERGE()')->where('id', 1)->limit(10);

    expect(tidbQuery()->hint('USE_INDEX(orders, idx_a)', 'READ_FROM_STORAGE(TIKV[orders])')->timeout(3)->where('id', 1)->toSql())
        ->toBe('select /*+ USE_INDEX(orders, idx_a) READ_FROM_STORAGE(TIKV[orders]) MAX_EXECUTION_TIME(3000) */ * from `orders` where `id` = ?')
        ->and($update->getGrammar()->compileUpdate($update, ['a' => 1]))
        ->toBe('update /*+ MEMORY_QUOTA(1 GB) */ `orders` set `a` = ? where `id` = ? order by `created_at` asc limit 5')
        ->and($delete->getGrammar()->compileDelete($delete))
        ->toBe('delete /*+ NO_INDEX_MERGE() */ from `orders` where `id` = ? limit 10')
        ->and(fn () => tidbQuery()->hint('A() */ drop'))->toThrow(InvalidArgumentException::class);
});

test('an aggregate keeps the hints on the inner select', function (): void {
    $query = tidbQuery()->hint('STREAM_AGG()');
    $query->aggregate = ['function' => 'count', 'columns' => ['*']];

    expect($query->toSql())->toBe('select /*+ STREAM_AGG() */ count(*) as `aggregate` from `orders`');
});

test('asOf reads every table in the statement at the same time and blocks writes', function (): void {
    $at = CarbonImmutable::parse('2026-09-29T10:00:00.123456Z');

    expect(tidbQuery('orders as o')->asOf($at)->join('users as u', 'u.id', '=', 'o.user_id')->toSql())
        ->toBe('select * from `orders` as `o` as of timestamp from_unixtime(1790676000.123456) inner join `users` as `u` as of timestamp from_unixtime(1790676000.123456) on `u`.`id` = `o`.`user_id`')
        ->and(tidbQuery()->asOf(DB::raw('now() - interval 5 second'))->toSql())->toBe('select * from `orders` as of timestamp now() - interval 5 second')
        ->and(fn () => tidbQuery()->asOf($at)->getGrammar()->compileDelete(tidbQuery()->asOf($at)))->toThrow(LogicException::class);
});

test('LIKE follows the requested case sensitivity on the utf8mb4_bin default collation', function (): void {
    expect(tidbQuery()->whereLike('name', 'A%')->toSql())->toBe('select * from `orders` where lower(`name`) like lower(?)')
        ->and(tidbQuery()->whereLike('name', 'A%', caseSensitive: true)->toSql())->toBe('select * from `orders` where `name` like binary ?')
        ->and(tidbQuery()->whereNotLike('name', 'A%')->toSql())->toBe('select * from `orders` where lower(`name`) not like lower(?)');
});

test('upsert always updates from VALUES() because TiDB does not bind the row alias', function (): void {
    expect(tidbQuery()->getGrammar()->compileUpsert(tidbQuery(), [['a' => 1, 'b' => 2]], ['a'], ['b', 'c' => 3]))
        ->toBe('insert into `orders` (`a`, `b`) values (?, ?) on duplicate key update `b` = values(`b`), `c` = ?');
});

test('only locks that TiDB takes compile', function (): void {
    expect(tidbQuery()->lockForUpdate()->toSql())->toBe('select * from `orders` for update')
        ->and(tidbQuery()->lock('for update of orders nowait')->toSql())->toBe('select * from `orders` for update of orders nowait')
        ->and(tidbQuery()->lock('FOR UPDATE WAIT 5')->toSql())->toBe('select * from `orders` FOR UPDATE WAIT 5')
        ->and(fn () => tidbQuery()->sharedLock()->toSql())->toThrow(UnsupportedFeatureException::class)
        ->and(fn () => tidbQuery()->lock('for update skip locked')->toSql())->toThrow(UnsupportedFeatureException::class);
});

test('a multi-table DELETE rejects ORDER BY and LIMIT, full-text search is rejected', function (): void {
    $query = tidbQuery()->join('users', 'users.id', '=', 'orders.user_id')->orderBy('orders.id')->limit(1);

    expect(fn () => $query->getGrammar()->compileDelete($query))->toThrow(UnsupportedFeatureException::class)
        ->and(fn () => tidbQuery()->whereFullText('body', 'x')->toSql())->toThrow(UnsupportedFeatureException::class);
});

test('JSON paths, vector distance and index hints compile', function (): void {
    expect(tidbQuery()->where('meta->a->b', 'x')->toSql())->toBe('select * from `orders` where json_unquote(json_extract(`meta`, \'$."a"."b"\')) = ?')
        ->and(tidbQuery()->whereJsonContains('tags', 'x')->toSql())->toBe('select * from `orders` where json_contains(`tags`, ?)')
        ->and(tidbQuery()->whereNull('meta->a')->toSql())->toBe('select * from `orders` where (json_extract(`meta`, \'$."a"\') is null OR json_type(json_extract(`meta`, \'$."a"\')) = \'NULL\')')
        ->and(tidbQuery()->selectVectorDistance('embedding', [0.1, 0.2])->toSql())->toBe('select vec_cosine_distance(`embedding`, ?) as `embedding_distance` from `orders`')
        ->and(tidbQuery()->forceIndex('idx_a')->toSql())->toBe('select * from `orders` force index (idx_a)')
        ->and(tidbQuery()->straightJoin('users', 'users.id', '=', 'orders.user_id')->toSql())->toBe('select * from `orders` straight_join `users` on `users`.`id` = `orders`.`user_id`');
});
