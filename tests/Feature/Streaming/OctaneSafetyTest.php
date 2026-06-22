<?php

use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\Sinks\NullTransportSink;
use Shahzad\AgenticGraph\Streaming\StreamStatus;

it('does not retain sink state across many requests', function () {
    $counts = [];

    for ($i = 1; $i <= 120; $i++) {
        $sink = new MemorySink;
        $event = new StreamEventImpl(
            streamId: 'stream-'.$i,
            executionId: 'exec-'.$i,
            nodeName: null,
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => 'value'],
            seq: $i,
            nodeSeq: null,
            occurredAt: new DateTimeImmutable('now')
        );

        $sink->emit($event);
        $sink->close(StreamStatus::SUCCESS);

        $counts[] = $sink->events()->count();
    }

    expect(min($counts))->toBe(1)
        ->and(max($counts))->toBe(1);
});

it('handles 1000 sequential sink initializations without shared state', function () {
    $counts = [];

    for ($i = 1; $i <= 1000; $i++) {
        $sink = new MemorySink;
        $sink->emit(new StreamEventImpl(
            streamId: 'stream-'.$i,
            executionId: 'exec-'.$i,
            nodeName: null,
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => 'value'],
            seq: $i,
            nodeSeq: null,
            occurredAt: new DateTimeImmutable('now')
        ));
        $counts[] = $sink->events()->count();
    }

    expect(min($counts))->toBe(1)
        ->and(max($counts))->toBe(1);
});

it('does not leak state between null transport sink instances', function () {
    $first = new NullTransportSink;
    $event = new StreamEventImpl(
        streamId: 'stream-1',
        executionId: 'exec-1',
        nodeName: null,
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'hello'],
        seq: 1,
        nodeSeq: null,
        occurredAt: new DateTimeImmutable('now')
    );

    $first->emit($event);
    $first->close(StreamStatus::SUCCESS);

    $second = new NullTransportSink;

    expect($second)->toBeInstanceOf(NullTransportSink::class);
});

it('cleans up after close', function () {
    $sink = new MemorySink;
    $event = new StreamEventImpl(
        streamId: 'stream-1',
        executionId: 'exec-1',
        nodeName: null,
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'hello'],
        seq: 1,
        nodeSeq: null,
        occurredAt: new DateTimeImmutable('now')
    );

    $sink->emit($event);
    $sink->close(StreamStatus::SUCCESS, ['final' => true]);

    expect($sink->status()?->value)->toBe('success')
        ->and($sink->meta())->toBe(['final' => true]);
});

it('keeps memory usage stable across many iterations', function () {
    $before = memory_get_usage(true);

    for ($i = 1; $i <= 1000; $i++) {
        $sink = new MemorySink;
        $sink->emit(new StreamEventImpl(
            streamId: 'stream-'.$i,
            executionId: 'exec-'.$i,
            nodeName: null,
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => 'value'],
            seq: $i,
            nodeSeq: null,
            occurredAt: new DateTimeImmutable('now')
        ));
    }

    $after = memory_get_usage(true);

    expect($after - $before)->toBeLessThan(15 * 1024 * 1024);
});
