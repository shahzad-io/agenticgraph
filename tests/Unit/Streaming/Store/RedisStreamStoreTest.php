<?php

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Store\RedisStreamStore;

test('redis stream store append read and truncate', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis is not available');
    }

    $store = new RedisStreamStore;
    $streamId = (string) Str::uuid();
    Redis::connection()->del('agenticgraph:stream:'.$streamId);

    $store->append(new StreamEventImpl(
        streamId: $streamId,
        executionId: 'exec-1',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'a'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
    ));
    $store->append(new StreamEventImpl(
        streamId: $streamId,
        executionId: 'exec-1',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'b'],
        seq: 2,
        nodeSeq: 2,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:01Z')
    ));
    $store->append(new StreamEventImpl(
        streamId: $streamId,
        executionId: 'exec-1',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'c'],
        seq: 3,
        nodeSeq: 3,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:02Z')
    ));

    $events = iterator_to_array($store->readFrom($streamId, 1, 10));

    expect($events)->toHaveCount(3)
        ->and($store->lastSeq($streamId))->toBe(3)
        ->and($events[0]->seq())->toBe(1)
        ->and($events[2]->seq())->toBe(3);

    $store->truncate($streamId, 3);
    $after = iterator_to_array($store->readFrom($streamId, 1, 10));

    expect($after)->not->toBeEmpty()
        ->and($store->lastSeq($streamId))->toBe(3);

    $afterSeqs = array_map(fn ($event) => $event->seq(), $after);
    expect(min($afterSeqs))->toBeGreaterThanOrEqual(3);
});

test('redis stream store replays from cursor', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis is not available');
    }

    $store = new RedisStreamStore;
    $streamId = (string) Str::uuid();
    Redis::connection()->del('agenticgraph:stream:'.$streamId);

    foreach ([1, 2, 3, 4] as $seq) {
        $store->append(new StreamEventImpl(
            streamId: $streamId,
            executionId: 'exec-1',
            nodeName: 'node-a',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => (string) $seq],
            seq: $seq,
            nodeSeq: $seq,
            occurredAt: new DateTimeImmutable('2024-01-01T00:00:0'.$seq.'Z')
        ));
    }

    $events = iterator_to_array($store->readFrom($streamId, 3, 10));
    $seqs = array_map(fn ($event) => $event->seq(), $events);

    expect($seqs)->toBe([3, 4]);
});

test('redis stream store auto trims by max length', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis is not available');
    }

    config()->set('agenticgraph.streaming.store.redis.max_len', 5);
    $store = new RedisStreamStore;
    $streamId = (string) Str::uuid();
    Redis::connection()->del('agenticgraph:stream:'.$streamId);

    for ($seq = 1; $seq <= 10; $seq++) {
        $store->append(new StreamEventImpl(
            streamId: $streamId,
            executionId: 'exec-1',
            nodeName: 'node-a',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => (string) $seq],
            seq: $seq,
            nodeSeq: $seq,
            occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
        ));
    }

    $events = iterator_to_array($store->readFrom($streamId, 1, 20));

    expect(count($events))->toBeLessThanOrEqual(5)
        ->and($store->lastSeq($streamId))->toBe(10);
});

test('redis stream store expires events with ttl', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis is not available');
    }

    config()->set('agenticgraph.streaming.store.redis.ttl', 1);
    $store = new RedisStreamStore;
    $streamId = (string) Str::uuid();
    $key = 'agenticgraph:stream:'.$streamId;
    Redis::connection()->del($key);

    $store->append(new StreamEventImpl(
        streamId: $streamId,
        executionId: 'exec-1',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'a'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
    ));

    usleep(1500000);

    expect(Redis::connection()->exists($key))->toBe(0);
});

test('redis stream store handles large event volume', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis is not available');
    }

    config()->set('agenticgraph.streaming.store.redis.max_len', 0);
    $store = new RedisStreamStore;
    $streamId = (string) Str::uuid();
    Redis::connection()->del('agenticgraph:stream:'.$streamId);

    $startedAt = microtime(true);
    for ($seq = 1; $seq <= 10000; $seq++) {
        $store->append(new StreamEventImpl(
            streamId: $streamId,
            executionId: 'exec-1',
            nodeName: 'node-a',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => 'x'],
            seq: $seq,
            nodeSeq: $seq,
            occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
        ));
    }
    $duration = microtime(true) - $startedAt;

    expect($store->lastSeq($streamId))->toBe(10000)
        ->and($duration)->toBeLessThan(10.0);
});

test('redis stream store handles malformed payload json', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis is not available');
    }

    $store = new RedisStreamStore;
    $streamId = (string) Str::uuid();
    $key = 'agenticgraph:stream:'.$streamId;
    Redis::connection()->del($key);

    Redis::connection()->command('xadd', [
        $key,
        '1-0',
        [
            'stream_id' => $streamId,
            'execution_id' => 'exec-1',
            'node_name' => 'node-a',
            'type' => 'text_delta',
            'seq' => 1,
            'node_seq' => 1,
            'occurred_at' => '2024-01-01T00:00:00Z',
            'payload' => '{invalid-json',
        ],
    ]);

    $events = iterator_to_array($store->readFrom($streamId, 1, 10));

    expect($events)->toHaveCount(1)
        ->and($events[0]->payload())->toBe([]);
});

test('redis stream store reports firstSeq', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis is not available');
    }

    $store = new RedisStreamStore;
    $streamId = (string) Str::uuid();

    expect($store->firstSeq($streamId))->toBeNull();

    foreach ([1, 2, 3, 4] as $seq) {
        $store->append(new StreamEventImpl(
            streamId: $streamId,
            executionId: 'exec-first',
            nodeName: null,
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => (string) $seq],
            seq: $seq,
            nodeSeq: null,
            occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
        ));
    }

    expect($store->firstSeq($streamId))->toBe(1);

    $store->truncate($streamId, 3);

    expect($store->firstSeq($streamId))->toBe(3)
        ->and($store->lastSeq($streamId))->toBe(4);

    Redis::connection()->del('agenticgraph:stream:'.$streamId);
});
