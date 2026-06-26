<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;
use Shahzad\AgenticGraph\Streaming\StreamErrorPayload;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;

test('stream error payload json serializes to snake_case fields on the wire', function () {
    $payload = new StreamErrorPayload(
        errorType: RuntimeException::class,
        message: 'store persist failed',
        stage: 'store',
        eventType: 'text_delta',
        eventSeq: 7,
        eventNodeName: 'alpha',
        eventNodeSeq: 3
    );

    $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $decoded = json_decode($encoded, true);

    expect($decoded)->toBe([
        'error_type' => RuntimeException::class,
        'message' => 'store persist failed',
        'stage' => 'store',
        'event_type' => 'text_delta',
        'event_seq' => 7,
        'event_node_name' => 'alpha',
        'event_node_seq' => 3,
    ]);
});

test('broadcast stream_error envelope wire body includes error details not empty object', function () {
    $errorPayload = new StreamErrorPayload(
        errorType: RuntimeException::class,
        message: 'broadcast transport failed',
        stage: 'transport',
        eventType: 'text_delta',
        eventSeq: 2,
        eventNodeName: 'node-a',
        eventNodeSeq: 1
    );

    $event = new StreamEventImpl(
        streamId: 'stream-error-wire',
        executionId: 'exec-error-wire',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: StreamEventEnvelope::TYPE_STREAM_ERROR,
        payload: $errorPayload,
        seq: 2,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
    );

    $wire = StreamEventEnvelope::fromStreamEvent($event)->toWireArray();
    $encoded = json_encode($wire, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $decoded = json_decode($encoded, true);

    expect($decoded['payload'])->toBe([
        'error_type' => RuntimeException::class,
        'message' => 'broadcast transport failed',
        'stage' => 'transport',
        'event_type' => 'text_delta',
        'event_seq' => 2,
        'event_node_name' => 'node-a',
        'event_node_seq' => 1,
    ])->and($decoded['payload'])->not->toBe([]);

    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->once()->with(
        ['agenticgraph.stream.stream-error-wire'],
        StreamEventEnvelope::TYPE_STREAM_ERROR,
        $wire
    );

    $sink = new BroadcastSink($broadcaster, 'stream-error-wire');
    $sink->emit($event);
});

test('sse stream_error data line carries error fields', function () {
    $errorPayload = new StreamErrorPayload(
        errorType: RuntimeException::class,
        message: 'sse store failed',
        stage: 'store',
        eventType: 'text_delta',
        eventSeq: 5,
        eventNodeName: 'node-b',
        eventNodeSeq: 2
    );

    $event = new StreamEventImpl(
        streamId: 'stream-sse-error',
        executionId: 'exec-sse-error',
        nodeName: 'node-b',
        spanId: null,
        parentSpanId: null,
        type: StreamEventEnvelope::TYPE_STREAM_ERROR,
        payload: $errorPayload,
        seq: 5,
        nodeSeq: 2,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
    );

    $content = StreamEventEnvelope::fromStreamEvent($event)->formatSse();
    preg_match('/^data: (.+)$/m', $content, $matches);
    $decoded = json_decode($matches[1], true);

    expect($decoded['payload']['message'])->toBe('sse store failed')
        ->and($decoded['payload']['stage'])->toBe('store')
        ->and($decoded['payload']['error_type'])->toBe(RuntimeException::class);
});
