<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Mockery;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\RetentionPolicyFactory;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\Sinks\NullTransportSink;
use Shahzad\AgenticGraph\Streaming\StreamErrorHandler;
use Shahzad\AgenticGraph\Streaming\StreamObserverFactory;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Streaming\StreamSinkFactory;

afterEach(function () {
    Mockery::close();
});

test('stream sink factory creates sink types by delivery', function () {
    $broadcaster = Mockery::mock(Broadcaster::class);
    $store = Mockery::mock(StreamStoreInterface::class);
    $factory = new StreamSinkFactory(
        $broadcaster,
        $store,
        false,
        100,
        false,
        new RetentionPolicyFactory,
        new StreamObserverFactory,
        new StreamErrorHandler
    );

    $session = new StreamSession(
        streamId: 'stream-1',
        executionId: 'exec-1',
        options: new StreamOptions(delivery: 'broadcast'),
        startedAtUnix: time()
    );

    $broadcastSink = $factory->create($session, new StreamOptions(delivery: 'broadcast'));
    $sseSink = $factory->create($session, new StreamOptions(delivery: 'sse'));
    $iteratorSink = $factory->create($session, new StreamOptions(delivery: 'iterator'));
    $memorySink = $factory->create($session, new StreamOptions(delivery: 'memory'));

    expect($broadcastSink)->toBeInstanceOf(BroadcastSink::class)
        ->and($sseSink)->toBeInstanceOf(NullTransportSink::class)
        ->and($iteratorSink)->toBeInstanceOf(MemorySink::class)
        ->and($memorySink)->toBeInstanceOf(MemorySink::class);
});
