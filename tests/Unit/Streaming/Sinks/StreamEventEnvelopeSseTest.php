<?php

use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamStatus;

test('stream event envelope formats sse wire output', function () {
    $event = new StreamEventImpl(
        streamId: 'stream-1',
        executionId: 'exec-1',
        nodeName: 'node-a',
        spanId: 'span-1',
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'hello'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
    );

    $envelope = StreamEventEnvelope::fromStreamEvent($event);
    $content = $envelope->formatSse();
    $close = StreamEventEnvelope::formatSseRaw(
        '2',
        StreamEventEnvelope::TYPE_STREAM_CLOSE,
        StreamEventEnvelope::streamCloseWirePayload(StreamStatus::SUCCESS, ['finish' => 'ok'])
    );

    expect($content)->toContain('id: 1')
        ->and($content)->toContain('event: text_delta')
        ->and($close)->toContain('event: stream_close')
        ->and($close)->toContain('"status":"success"');
});

test('stream event envelope wire body omits duplicate type field', function () {
    $event = new StreamEventImpl(
        streamId: 'stream-1',
        executionId: 'exec-1',
        nodeName: 'node-a',
        spanId: 'span-1',
        parentSpanId: 'span-0',
        type: 'text_delta',
        payload: ['delta' => 'hello'],
        seq: 5,
        nodeSeq: 2,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
    );

    $content = StreamEventEnvelope::fromStreamEvent($event)->formatSse();
    $lines = array_values(array_filter(explode("\n", $content)));
    $dataLine = '';
    foreach ($lines as $line) {
        if (str_starts_with($line, 'data: ')) {
            $dataLine = substr($line, 6);
            break;
        }
    }
    $payload = json_decode($dataLine, true);

    expect($payload)->toBe(StreamEventEnvelope::fromStreamEvent($event)->toWireArray())
        ->and($payload)->not->toHaveKey('type');
});
