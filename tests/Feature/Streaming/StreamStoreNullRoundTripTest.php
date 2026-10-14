<?php

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Store\DatabaseStreamStore;
use Shahzad\AgenticGraph\Streaming\Store\RedisStreamStore;

function nullRoundTripFrame(string $streamId, int $seq, ?string $nodeName, ?string $spanId, ?string $parentSpanId, ?int $nodeSeq): StreamEventImpl
{
    return new StreamEventImpl(
        streamId: $streamId,
        executionId: 'exec-null-round-trip',
        nodeName: $nodeName,
        spanId: $spanId,
        parentSpanId: $parentSpanId,
        type: 'execution_terminal',
        payload: ['status' => 'completed', 'final_state' => null],
        seq: $seq,
        nodeSeq: $nodeSeq,
        occurredAt: new DateTimeImmutable('2026-10-14T12:00:00+00:00')
    );
}

function nullRoundTripFields(StreamEvent $event): array
{
    return [
        'stream_id' => $event->streamId(),
        'execution_id' => $event->executionId(),
        'node_name' => $event->nodeName(),
        'span_id' => $event->spanId(),
        'parent_span_id' => $event->parentSpanId(),
        'type' => $event->type(),
        'payload' => $event->payload(),
        'seq' => $event->seq(),
        'node_seq' => $event->nodeSeq(),
        'occurred_at' => $event->occurredAt()->format(DATE_ATOM),
    ];
}

function assertNullRoundTrip(StreamStoreInterface $store, string $streamId): void
{
    $frames = [
        nullRoundTripFrame($streamId, 1, null, null, null, null),
        nullRoundTripFrame($streamId, 2, 'node-a', 'span-1', 'span-0', 3),
    ];
    foreach ($frames as $frame) {
        $store->append($frame);
    }

    $read = array_values(iterator_to_array($store->readFrom($streamId, 1, 10), false));

    expect(array_map('nullRoundTripFields', $read))->toBe(array_map('nullRoundTripFields', $frames));
}

function redisAvailableForRoundTrip(): bool
{
    try {
        Redis::connection()->ping();

        return true;
    } catch (Throwable) {
        return false;
    }
}

it('round-trips null nullable fields through the redis stream store', function () {
    if (! redisAvailableForRoundTrip()) {
        $this->markTestSkipped('Redis is not available');
    }

    $streamId = (string) Str::uuid();

    try {
        assertNullRoundTrip(new RedisStreamStore, $streamId);
    } finally {
        Redis::connection()->del('agenticgraph:stream:'.$streamId);
    }
});

it('reads entries written before null-skipping ("" fields) back as null', function () {
    if (! redisAvailableForRoundTrip()) {
        $this->markTestSkipped('Redis is not available');
    }

    $streamId = (string) Str::uuid();
    $key = 'agenticgraph:stream:'.$streamId;

    try {
        Redis::connection()->command('xadd', [$key, '1-0', [
            'stream_id' => $streamId,
            'execution_id' => 'exec-legacy',
            'node_name' => '',
            'span_id' => '',
            'parent_span_id' => '',
            'type' => 'execution_terminal',
            'seq' => '1',
            'node_seq' => '',
            'occurred_at' => '2026-10-14T12:00:00+00:00',
            'payload' => '{"status":"completed","final_state":null}',
        ]]);

        $read = array_values(iterator_to_array((new RedisStreamStore)->readFrom($streamId, 1, 10), false));

        expect($read)->toHaveCount(1)
            ->and($read[0]->nodeName())->toBeNull()
            ->and($read[0]->spanId())->toBeNull()
            ->and($read[0]->parentSpanId())->toBeNull()
            ->and($read[0]->nodeSeq())->toBeNull()
            ->and($read[0]->seq())->toBe(1)
            ->and($read[0]->payload())->toBe(['status' => 'completed', 'final_state' => null]);
    } finally {
        Redis::connection()->del($key);
    }
});

it('round-trips null nullable fields through the database stream store', function () {
    assertNullRoundTrip(new DatabaseStreamStore, (string) Str::uuid());
});
