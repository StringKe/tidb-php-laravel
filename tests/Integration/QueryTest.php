<?php

use Illuminate\Database\Eloquent\Model;
use StringKe\TidbPhp\Laravel\TidbBlueprint;
use StringKe\TidbPhp\Laravel\TidbQueryBuilder;

beforeEach(function (): void {
    $this->tidb->getSchemaBuilder()->create('orders', function (TidbBlueprint $table): void {
        $table->autoRandom();
        $table->string('no', 20)->unique('orders_no_unique');
        $table->integer('amount');
        $table->json('meta')->nullable();
        $table->datetime('updated_at', 6)->nullable();
        $table->index('amount', 'orders_amount_index');
    });

    $this->tidb->table('orders')->insert(array_map(fn (int $i): array => ['no' => "n{$i}", 'amount' => $i, 'meta' => json_encode(['tag' => $i % 2 === 0 ? 'Even' : 'odd'])], range(1, 10)));
});

function orders(): TidbQueryBuilder
{
    $query = test()->tidb->table('orders');
    expect($query)->toBeInstanceOf(TidbQueryBuilder::class);

    return $query;
}

test('hints, the query timeout and index hints execute on the server', function (): void {
    expect(orders()->hint('USE_INDEX(orders, orders_amount_index)')->timeout(5)->forceIndex('orders_amount_index')->where('amount', '>', 8)->orderBy('amount')->pluck('amount')->all())->toBe([9, 10])
        ->and(orders()->hint('STREAM_AGG()')->count())->toBe(10)
        ->and(orders()->hint('MEMORY_QUOTA(1 GB)')->where('amount', 1)->update(['amount' => 11]))->toBe(1)
        ->and(orders()->hint('NO_INDEX_MERGE()')->where('amount', 11)->delete())->toBe(1);
});

test('asOf reads the snapshot from before a change', function (): void {
    $at = new DateTimeImmutable((string) test()->tidb->scalar('select now(6)'), new DateTimeZone('UTC'));

    // TiDB 的读时间戳由服务端时钟给出，Carbon 冻结拨不动它。
    usleep(1_200_000);
    orders()->where('no', 'n1')->update(['amount' => 100]);

    expect(orders()->asOf($at)->where('no', 'n1')->value('amount'))->toBe(1)
        ->and(orders()->where('no', 'n1')->value('amount'))->toBe(100);
});

test('non-transactional batch DML splits the statement and applies it to every row', function (): void {
    $dryRun = orders()->where('amount', '>', 0)->batchUpdate('id', 3, ['amount' => 0], dryRun: true);

    expect($dryRun)->not->toBe([])
        ->and(orders()->where('amount', 0)->count())->toBe(0);

    orders()->where('amount', '>', 5)->batchUpdate('id', 2, ['amount' => 0]);
    expect(orders()->where('amount', 0)->count())->toBe(5);

    test()->tidb->getSchemaBuilder()->create('orders_archive', function (TidbBlueprint $table): void {
        $table->autoRandom();
        $table->string('no', 20);
    });
    test()->tidb->table('orders_archive')->batchInsertUsing('orders.id', 4, ['no'], orders()->select('no'));
    expect(test()->tidb->table('orders_archive')->count())->toBe(10);

    orders()->where('amount', 0)->batchDelete('id', 2);
    expect(orders()->count())->toBe(5);
});

test('the Eloquent batch macros forward to the TiDB builder and touch updated_at', function (): void {
    $model = new class extends Model
    {
        protected $table = 'orders';

        protected $connection = 'tidb';

        public const CREATED_AT = null;
    };

    $model->newQuery()->where('amount', '<', 3)->batchUpdate('id', 1, ['amount' => 50]);

    expect(orders()->where('amount', 50)->whereNotNull('updated_at')->count())->toBe(2);
});

test('JSON paths, case-insensitive LIKE, upsert and locks run as compiled', function (): void {
    orders()->upsert([['no' => 'n1', 'amount' => 7], ['no' => 'n99', 'amount' => 99]], ['no'], ['amount']);

    expect(orders()->where('meta->tag', 'odd')->count())->toBe(5)
        ->and(orders()->whereLike('meta->tag', 'even')->count())->toBe(5)
        ->and(orders()->whereLike('meta->tag', 'even', caseSensitive: true)->count())->toBe(0)
        ->and(orders()->where('no', 'n1')->value('amount'))->toBe(7)
        ->and(orders()->count())->toBe(11)
        ->and(test()->tidb->transaction(fn () => orders()->where('no', 'n2')->lockForUpdate()->value('amount')))->toBe(2)
        ->and(test()->tidb->transaction(fn () => orders()->where('no', 'n2')->lock('for update nowait')->value('amount')))->toBe(2);
});
