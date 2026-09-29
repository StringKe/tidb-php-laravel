<?php

use Illuminate\Database\UniqueConstraintViolationException;
use PHPUnit\Framework\Assert;
use StringKe\TidbPhp\Laravel\TidbBlueprint;

beforeEach(function (): void {
    $this->tidb->getSchemaBuilder()->create('orders', function (TidbBlueprint $table): void {
        $table->autoRandom();
        $table->string('no', 20)->unique('orders_no_unique');
        $table->integer('amount');
    });
});

test('insertGetId returns the AUTO_RANDOM value TiDB generated', function (): void {
    $id = $this->tidb->table('orders')->insertGetId(['no' => 'a', 'amount' => 1]);

    expect($id)->toBeInt()->toBeGreaterThan(0)->toBeLessThan(2 ** 53)
        ->and($this->tidb->table('orders')->where('id', $id)->value('no'))->toBe('a');
});

test('a duplicate key raises the unique violation with the index name', function (): void {
    $this->tidb->table('orders')->insert(['no' => 'a', 'amount' => 1]);

    $violation = function (): UniqueConstraintViolationException {
        try {
            $this->tidb->table('orders')->insert(['no' => 'a', 'amount' => 2]);
        } catch (UniqueConstraintViolationException $e) {
            return $e;
        }

        Assert::fail('The second insert of the same order number did not violate the unique key.');
    };

    expect($violation()->index)->toBe('orders_no_unique');
});

test('a stale read sees the rows as they were at the given time and refuses to nest in a transaction', function (): void {
    $id = $this->tidb->table('orders')->insertGetId(['no' => 'a', 'amount' => 1]);
    $at = (string) $this->tidb->scalar('select now(6)');

    // TiDB 的读时间戳由服务端时钟给出，Carbon 冻结拨不动它。
    usleep(1_200_000);
    $this->tidb->table('orders')->where('id', $id)->update(['amount' => 2]);

    $stale = $this->tidb->staleRead(new DateTimeImmutable($at, new DateTimeZone('UTC')), fn ($connection) => $connection->table('orders')->where('id', $id)->value('amount'));

    expect($stale)->toBe(1)
        ->and($this->tidb->table('orders')->where('id', $id)->value('amount'))->toBe(2)
        ->and(fn () => $this->tidb->transaction(fn () => $this->tidb->staleRead(new DateTimeImmutable, fn () => null)))->toThrow(LogicException::class);
});

test('session helpers reach the server', function (): void {
    $this->tidb->statement('set @marker = 1');
    $this->tidb->resetSession();

    expect($this->tidb->ping())->toBeTrue()
        ->and($this->tidb->connectionId())->toBeGreaterThan(0)
        ->and($this->tidb->scalar('select @marker'))->toBeNull()
        ->and($this->tidb->scalar('select @@session.time_zone'))->toBe('+00:00');
});

test('LOAD DATA LOCAL loads rows into the table', function (): void {
    $count = $this->tidb->loadData("load data local infile 'rows.csv' into table `orders` fields terminated by ',' (`no`, `amount`)", "a,1\nb,2\n");

    expect($count)->toBe(2)
        ->and($this->tidb->table('orders')->orderBy('no')->pluck('amount', 'no')->all())->toBe(['a' => 1, 'b' => 2]);
});
