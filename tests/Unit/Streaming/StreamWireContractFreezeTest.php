<?php

/**
 * Contract-freeze guard tests (v1.1).
 *
 * These tests exist to fail loudly when a frozen wire-contract field or framework
 * event type string changes accidentally. Versioning rule: before the first public
 * release, amendments are recorded in the docs/streaming/WIRE-CONTRACT.md version
 * history as v1.1. After the first public release, any breaking change requires a
 * major version bump and updating docs/streaming/WIRE-CONTRACT.md.
 */

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamLifecycleEventTypes;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Streaming\StreamStatus;
use Shahzad\AgenticGraph\Streaming\StreamTerminalStatus;

test('contract freeze guard: toWireArray emits exactly the nine frozen snake_case keys', function () {
    $event = new StreamEventImpl(
        streamId: 'stream-freeze',
        executionId: 'exec-freeze',
        nodeName: 'alpha',
        spanId: 'span-1',
        parentSpanId: 'span-0',
        type: 'text_delta',
        payload: ['delta' => 'x'],
        seq: 3,
        nodeSeq: 2,
        occurredAt: new DateTimeImmutable('2024-06-01T12:00:00+00:00')
    );

    $wire = StreamEventEnvelope::fromStreamEvent($event)->toWireArray();

    expect(array_keys($wire))->toBe(StreamEventEnvelope::FROZEN_WIRE_BODY_KEYS)
        ->and($wire)->not->toHaveKey('type');
});

test('contract freeze guard: frozen wire body keys constant matches documented order', function () {
    expect(StreamEventEnvelope::FROZEN_WIRE_BODY_KEYS)->toBe([
        'stream_id',
        'execution_id',
        'node_name',
        'span_id',
        'parent_span_id',
        'seq',
        'node_seq',
        'occurred_at',
        'payload',
    ]);
});

test('contract freeze guard: closed framework lifecycle type strings are frozen', function () {
    expect(StreamLifecycleEventTypes::GRAPH_START)->toBe('graph_start')
        ->and(StreamLifecycleEventTypes::GRAPH_COMPLETE)->toBe('graph_complete')
        ->and(StreamLifecycleEventTypes::GRAPH_FAILED)->toBe('graph_failed')
        ->and(StreamLifecycleEventTypes::NODE_START)->toBe('node_start')
        ->and(StreamLifecycleEventTypes::NODE_COMPLETE)->toBe('node_complete')
        ->and(StreamLifecycleEventTypes::NODE_ERROR)->toBe('node_error')
        ->and(StreamLifecycleEventTypes::NODE_INTERRUPT)->toBe('node_interrupt')
        ->and(StreamLifecycleEventTypes::STREAM_RESUME)->toBe('stream_resume')
        ->and(StreamEventEnvelope::TYPE_EXECUTION_TERMINAL)->toBe('execution_terminal')
        ->and(StreamEventEnvelope::TYPE_STREAM_CLOSE)->toBe('stream_close')
        ->and(StreamEventEnvelope::TYPE_STREAM_ERROR)->toBe('stream_error');
});

test('contract freeze guard: broadcast delivery uses event name as type and body without type field', function () {
    $event = new StreamEventImpl(
        streamId: 'stream-broadcast-freeze',
        executionId: 'exec-broadcast-freeze',
        nodeName: 'alpha',
        spanId: null,
        parentSpanId: null,
        type: 'node_complete',
        payload: ['node_name' => 'alpha', 'duration_ms' => 1.0],
        seq: 9,
        nodeSeq: 5,
        occurredAt: new DateTimeImmutable('2024-06-01T12:00:00+00:00')
    );

    $wire = StreamEventEnvelope::fromStreamEvent($event)->toWireArray();

    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->once()->with(
        ['agenticgraph.stream.stream-broadcast-freeze'],
        'node_complete',
        Mockery::on(function (array $payload) use ($wire): bool {
            expect(array_keys($payload))->toBe(StreamEventEnvelope::FROZEN_WIRE_BODY_KEYS)
                ->and($payload)->not->toHaveKey('type');

            return $payload === $wire;
        })
    );

    $sink = new BroadcastSink($broadcaster, 'stream-broadcast-freeze');
    $sink->emit($event);
});

test('contract freeze guard: execution_terminal final_state is nullable and null by default (v1.1)', function () {
    $session = new StreamSession(
        streamId: 'stream-final-state-freeze',
        executionId: 'exec-final-state-freeze',
        options: new StreamOptions,
        startedAtUnix: 0,
    );

    $payload = StreamEventEnvelope::executionTerminal(
        $session,
        StreamTerminalStatus::COMPLETED,
        ['secret' => 'value']
    )->toWireArray()['payload'];

    expect($payload)->toHaveKey('final_state')
        ->and($payload['final_state'])->toBeNull()
        ->and(StreamEventEnvelope::streamCloseWirePayload(StreamStatus::SUCCESS, ['final_state' => ['secret' => 'value']]))
        ->toBe(['status' => 'success', 'meta' => ['final_state' => null]]);
});
