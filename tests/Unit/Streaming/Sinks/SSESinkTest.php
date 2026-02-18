<?php

use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Sinks\SSESink;
use Shahzad\AgenticGraph\Streaming\StreamStatus;

test('sse sink formats events', function () {
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

test('sse sink payload includes required fields', function () {
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

    expect($payload)->toBeArray()
        ->and($payload['stream_id'])->toBe('stream-1')
        ->and($payload['execution_id'])->toBe('exec-1')
        ->and($payload['node_name'])->toBe('node-a')
        ->and($payload['span_id'])->toBe('span-1')
        ->and($payload['parent_span_id'])->toBe('span-0')
        ->and($payload['type'])->toBe('text_delta')
        ->and($payload['seq'])->toBe(5)
        ->and($payload['node_seq'])->toBe(2)
        ->and($payload['payload'])->toBe(['delta' => 'hello']);
});
