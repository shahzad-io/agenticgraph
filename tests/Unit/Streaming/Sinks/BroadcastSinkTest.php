<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamStatus;

test('broadcast sink broadcasts unified envelope wire array', function () {
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
    $wire = StreamEventEnvelope::fromStreamEvent($event)->toWireArray();

    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->once()->with(
        ['agenticgraph.stream.stream-1'],
        'text_delta',
        $wire
    );
    $broadcaster->shouldReceive('broadcast')->once()->with(
        ['agenticgraph.stream.stream-1'],
        StreamEventEnvelope::TYPE_STREAM_CLOSE,
        StreamEventEnvelope::streamCloseWirePayload(StreamStatus::SUCCESS, ['finish' => 'ok'])
    );

    $sink = new BroadcastSink($broadcaster, 'stream-1');
    $sink->emit($event);
    $sink->close(StreamStatus::SUCCESS, ['finish' => 'ok']);
});
