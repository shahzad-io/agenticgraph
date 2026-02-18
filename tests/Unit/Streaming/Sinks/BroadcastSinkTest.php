<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;
use Shahzad\AgenticGraph\Streaming\StreamStatus;

test('broadcast sink broadcasts events', function () {
    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->once()->with(
        ['agenticgraph.stream.stream-1'],
        'text_delta',
        Mockery::on(function ($payload) {
            return $payload['stream_id'] === 'stream-1'
                && $payload['execution_id'] === 'exec-1'
                && $payload['type'] === 'text_delta'
                && $payload['seq'] === 1;
        })
    );
    $broadcaster->shouldReceive('broadcast')->once()->with(
        ['agenticgraph.stream.stream-1'],
        'stream_close',
        ['status' => 'success', 'meta' => ['finish' => 'ok']]
    );

    $sink = new BroadcastSink($broadcaster, 'stream-1');
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
    $sink->close(StreamStatus::SUCCESS, ['finish' => 'ok']);
});
