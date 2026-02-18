<?php

use Illuminate\Support\Str;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Store\DatabaseStreamStore;
use Shahzad\AgenticGraph\Streaming\Store\RetentionPolicy;

test('database stream store append read and truncate', function () {
    $store = new DatabaseStreamStore;
    $streamId = (string) Str::uuid();

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

    $events = iterator_to_array($store->readFrom($streamId, 1, 10));

    expect($events)->toHaveCount(2)
        ->and($store->lastSeq($streamId))->toBe(2)
        ->and($events[0]->seq())->toBe(1)
        ->and($events[1]->seq())->toBe(2);

    $store->truncate($streamId, 2);
    $after = iterator_to_array($store->readFrom($streamId, 1, 10));

    expect($after)->toHaveCount(1)
        ->and($after[0]->seq())->toBe(2);
});

test('database stream store replays from cursor', function () {
    $store = new DatabaseStreamStore;
    $streamId = (string) Str::uuid();

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

test('database stream store handles large payloads', function () {
    $store = new DatabaseStreamStore;
    $streamId = (string) Str::uuid();
    $payload = ['text' => str_repeat('x', 200000)];

    $store->append(new StreamEventImpl(
        streamId: $streamId,
        executionId: 'exec-1',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: $payload,
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
    ));

    $events = iterator_to_array($store->readFrom($streamId, 1, 10));

    expect($events)->toHaveCount(1)
        ->and($events[0]->payload())->toBe($payload);
});

test('database stream store handles 1k events', function () {
    $store = new DatabaseStreamStore;
    $streamId = (string) Str::uuid();

    for ($seq = 1; $seq <= 1000; $seq++) {
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

    $events = iterator_to_array($store->readFrom($streamId, 1, 1000));

    expect($events)->toHaveCount(1000)
        ->and($store->lastSeq($streamId))->toBe(1000);
});

test('database stream store handles payloads over 1mb', function () {
    $store = new DatabaseStreamStore;
    $streamId = (string) Str::uuid();
    $payload = ['text' => str_repeat('x', 1200000)];

    $store->append(new StreamEventImpl(
        streamId: $streamId,
        executionId: 'exec-1',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: $payload,
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
    ));

    $events = iterator_to_array($store->readFrom($streamId, 1, 10));

    expect($events)->toHaveCount(1)
        ->and($events[0]->payload())->toBe($payload);
});

test('retention policy trims database stream events by max events', function () {
    config()->set('agenticgraph.streaming.retention.max_events_per_stream', 3);
    config()->set('agenticgraph.streaming.retention.max_age_hours', 0);

    $store = new DatabaseStreamStore;
    $policy = new RetentionPolicy;
    $streamId = (string) Str::uuid();

    for ($seq = 1; $seq <= 5; $seq++) {
        $event = new StreamEventImpl(
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
        );
        $store->append($event);
        $policy->enforce($store, $event);
    }

    $events = iterator_to_array($store->readFrom($streamId, 1, 10));
    $seqs = array_map(fn ($event) => $event->seq(), $events);

    expect($seqs)->toBe([3, 4, 5]);
});
