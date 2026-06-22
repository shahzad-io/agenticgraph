<?php

use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Sinks\SSESink;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamStatus;

test('sse sink formats events from unified envelope', function () {
    $sink = new SSESink;
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

    $sink->emit($event);
    $sink->close(StreamStatus::SUCCESS, ['finish' => 'ok']);

    $content = $sink->content();

    expect($content)->toContain('id: 1')
        ->and($content)->toContain('event: text_delta')
        ->and($content)->toContain('event: stream_close')
        ->and($content)->toContain('"status":"success"');
});

test('sse sink wire body omits duplicate type field', function () {
    $sink = new SSESink;
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

    $sink->emit($event);

    $content = $sink->content();
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
