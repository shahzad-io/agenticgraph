<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Event;
use Mockery;
use Shahzad\AgenticGraph\Events\StreamStatsUpdated;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\RetentionPolicyFactory;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\Sinks\NullTransportSink;
use Shahzad\AgenticGraph\Streaming\Sinks\RateLimitedSink;
use Shahzad\AgenticGraph\Streaming\StreamClock;
use Shahzad\AgenticGraph\Streaming\StreamErrorDecision;
use Shahzad\AgenticGraph\Streaming\StreamErrorHandler;
use Shahzad\AgenticGraph\Streaming\StreamErrorPayload;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamMetrics;
use Shahzad\AgenticGraph\Streaming\StreamObserver;
use Shahzad\AgenticGraph\Streaming\StreamObserverFactory;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Streaming\StreamSinkFactory;
use Shahzad\AgenticGraph\Streaming\StreamStatus;
use Shahzad\AgenticGraph\Streaming\WrappedStreamSink;

test('memory sink stores events and close status', function () {
    $sink = new MemorySink;
    $event = new StreamEventImpl(
        streamId: 'stream-1',
        executionId: 'exec-1',
        nodeName: null,
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'a'],
        seq: 1,
        nodeSeq: null,
        occurredAt: new DateTimeImmutable('now')
    );

    $sink->emit($event);
    $sink->close(StreamStatus::SUCCESS, ['finish' => 'ok']);

    expect($sink->events()->count())->toBe(1)
        ->and($sink->status())->toBe(StreamStatus::SUCCESS)
        ->and($sink->meta())->toBe(['finish' => 'ok']);
});

test('memory sink iterates many events in order', function () {
    $sink = new MemorySink;

    for ($seq = 1; $seq <= 1000; $seq++) {
        $sink->emit(new StreamEventImpl(
            streamId: 'stream-1',
            executionId: 'exec-1',
            nodeName: 'node-a',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => (string) $seq],
            seq: $seq,
            nodeSeq: $seq,
            occurredAt: new DateTimeImmutable('now')
        ));
    }

    $lastSeq = null;
    foreach ($sink->events() as $event) {
        $lastSeq = $event->seq();
    }

    expect($sink->events()->count())->toBe(1000)
        ->and($lastSeq)->toBe(1000);
});

test('rate limited sink buffers until close', function () {
    $now = 0.0;
    $clock = new class($now) implements StreamClock
    {
        public float $now;

        public function __construct(float $now)
        {
            $this->now = $now;
        }

        public function now(): float
        {
            return $this->now;
        }
    };
    $inner = new MemorySink;
    $sink = new RateLimitedSink($inner, 2, $clock);

    for ($seq = 1; $seq <= 3; $seq++) {
        $sink->emit(new StreamEventImpl(
            streamId: 'stream-1',
            executionId: 'exec-1',
            nodeName: 'node-a',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => (string) $seq],
            seq: $seq,
            nodeSeq: $seq,
            occurredAt: new DateTimeImmutable('now')
        ));
    }

    expect($inner->events()->count())->toBe(2);

    $clock->now = 1.1;
    $sink->close(StreamStatus::SUCCESS);

    expect($inner->events()->count())->toBe(3);
});

test('wrapped sink emits stream_error on redis store failure', function () {
    $store = new class implements StreamStoreInterface
    {
        public function append(StreamEvent $event): void
        {
            throw new RuntimeException('Redis connection lost');
        }

        public function readFrom(string $streamId, int $fromSeq, int $limit = 200): Generator
        {
            if (false) {
                yield $streamId;
            }
        }

        public function lastSeq(string $streamId): ?int
        {
            return null;
        }

        public function truncate(string $streamId, int $beforeSeq): void {}
    };
    $baseSink = new MemorySink;
    $options = new StreamOptions(delivery: 'iterator', storeEnabled: true);
    $session = new StreamSession(
        streamId: 'stream-1',
        executionId: 'exec-1',
        options: $options,
        startedAtUnix: time()
    );

    $factory = new StreamSinkFactory(
        Mockery::mock(Broadcaster::class),
        $store,
        false,
        100,
        false,
        new RetentionPolicyFactory,
        new StreamObserverFactory,
        new StreamErrorHandler,
        null
    );
    $wrapped = $factory->wrap($baseSink, $options, $session);

    $event = new StreamEventImpl(
        streamId: 'stream-1',
        executionId: 'exec-1',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'a'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('now')
    );

    try {
        $wrapped->emit($event);
    } catch (RuntimeException) {
    }

    $events = iterator_to_array($baseSink->events());
    $payload = $events[0]->payload();

    expect($events)->toHaveCount(2)
        ->and($events[0]->type())->toBe('stream_error')
        ->and($events[1]->type())->toBe('text_delta')
        ->and($payload)->toBeInstanceOf(StreamErrorPayload::class)
        ->and($payload->stage)->toBe('store')
        ->and($baseSink->status())->toBeNull();
});

test('wrapped sink treats store failure as fatal when configured', function () {
    config()->set('agenticgraph.streaming.errors.store_fatal', true);

    $store = new class implements StreamStoreInterface
    {
        public function append(StreamEvent $event): void
        {
            throw new RuntimeException('Database connection lost');
        }

        public function readFrom(string $streamId, int $fromSeq, int $limit = 200): Generator
        {
            if (false) {
                yield $streamId;
            }
        }

        public function lastSeq(string $streamId): ?int
        {
            return null;
        }

        public function truncate(string $streamId, int $beforeSeq): void {}
    };
    $baseSink = new MemorySink;
    $options = new StreamOptions(delivery: 'iterator', storeEnabled: true);
    $session = new StreamSession(
        streamId: 'stream-2-fatal',
        executionId: 'exec-2-fatal',
        options: $options,
        startedAtUnix: time()
    );

    $factory = new StreamSinkFactory(
        Mockery::mock(Broadcaster::class),
        $store,
        false,
        100,
        false,
        new RetentionPolicyFactory,
        new StreamObserverFactory,
        new StreamErrorHandler,
        null
    );
    $wrapped = $factory->wrap($baseSink, $options, $session);

    $event = new StreamEventImpl(
        streamId: 'stream-2-fatal',
        executionId: 'exec-2-fatal',
        nodeName: 'node-b',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'b'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('now')
    );

    try {
        $wrapped->emit($event);
    } catch (RuntimeException) {
    }

    $events = iterator_to_array($baseSink->events());
    $payload = $events[0]->payload();

    expect($events)->toHaveCount(1)
        ->and($events[0]->type())->toBe('stream_error')
        ->and($payload)->toBeInstanceOf(StreamErrorPayload::class)
        ->and($payload->stage)->toBe('store')
        ->and($baseSink->status()?->value)->toBe('error');
});

test('wrapped sink emits stream_error on database store failure', function () {
    $store = new class implements StreamStoreInterface
    {
        public function append(StreamEvent $event): void
        {
            throw new RuntimeException('Database connection lost');
        }

        public function readFrom(string $streamId, int $fromSeq, int $limit = 200): Generator
        {
            if (false) {
                yield $streamId;
            }
        }

        public function lastSeq(string $streamId): ?int
        {
            return null;
        }

        public function truncate(string $streamId, int $beforeSeq): void {}
    };
    $baseSink = new MemorySink;
    $options = new StreamOptions(delivery: 'iterator', storeEnabled: true);
    $session = new StreamSession(
        streamId: 'stream-2',
        executionId: 'exec-2',
        options: $options,
        startedAtUnix: time()
    );

    $factory = new StreamSinkFactory(
        Mockery::mock(Broadcaster::class),
        $store,
        false,
        100,
        false,
        new RetentionPolicyFactory,
        new StreamObserverFactory,
        new StreamErrorHandler,
        null
    );
    $wrapped = $factory->wrap($baseSink, $options, $session);

    $event = new StreamEventImpl(
        streamId: 'stream-2',
        executionId: 'exec-2',
        nodeName: 'node-b',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'b'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('now')
    );

    try {
        $wrapped->emit($event);
    } catch (RuntimeException) {
    }

    $events = iterator_to_array($baseSink->events());
    $payload = $events[0]->payload();

    expect($events)->toHaveCount(2)
        ->and($events[0]->type())->toBe('stream_error')
        ->and($events[1]->type())->toBe('text_delta')
        ->and($payload)->toBeInstanceOf(StreamErrorPayload::class)
        ->and($payload->stage)->toBe('store')
        ->and($baseSink->status())->toBeNull();
});

test('wrapped sink handles client disconnect mid-stream', function () {
    $baseSink = new class implements StreamSinkInterface
    {
        public array $events = [];

        public ?StreamStatus $status = null;

        public function emit(StreamEvent $event): void
        {
            if ($event->type() !== 'stream_error') {
                throw new RuntimeException('client disconnected');
            }
            $this->events[] = $event;
        }

        public function close(StreamStatus $status, mixed $meta = null): void
        {
            $this->status = $status;
        }
    };

    $options = new StreamOptions(delivery: 'iterator', storeEnabled: false);
    $session = new StreamSession(
        streamId: 'stream-3',
        executionId: 'exec-3',
        options: $options,
        startedAtUnix: time()
    );

    $factory = new StreamSinkFactory(
        Mockery::mock(Broadcaster::class),
        Mockery::mock(StreamStoreInterface::class),
        false,
        100,
        false,
        new RetentionPolicyFactory,
        new StreamObserverFactory,
        new StreamErrorHandler,
        null
    );
    $wrapped = $factory->wrap($baseSink, $options, $session);

    $event = new StreamEventImpl(
        streamId: 'stream-3',
        executionId: 'exec-3',
        nodeName: 'node-c',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'c'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('now')
    );

    try {
        $wrapped->emit($event);
    } catch (RuntimeException) {
    }

    $payload = $baseSink->events[0]->payload();

    expect($baseSink->events)->toHaveCount(1)
        ->and($baseSink->events[0]->type())->toBe('stream_error')
        ->and($payload)->toBeInstanceOf(StreamErrorPayload::class)
        ->and($payload->stage)->toBe('transport')
        ->and($baseSink->status?->value)->toBe('error');
});

test('wrapped sink enforces retention and emits metrics on close', function () {
    config()->set('agenticgraph.streaming.retention.max_events_per_stream', 1);
    config()->set('agenticgraph.streaming.retention.max_age_hours', 0);

    $truncateCalls = [];
    $store = new class($truncateCalls) implements StreamStoreInterface
    {
        public array $events = [];

        private array $truncateCalls;

        public function __construct(array &$truncateCalls)
        {
            $this->truncateCalls = &$truncateCalls;
        }

        public function append(StreamEvent $event): void
        {
            $this->events[] = $event;
        }

        public function readFrom(string $streamId, int $fromSeq, int $limit = 200): Generator
        {
            if (false) {
                yield $streamId;
            }
        }

        public function lastSeq(string $streamId): ?int
        {
            return null;
        }

        public function truncate(string $streamId, int $beforeSeq): void
        {
            $this->truncateCalls[] = [$streamId, $beforeSeq];
        }
    };
    Event::fake([StreamStatsUpdated::class]);

    $baseSink = new MemorySink;
    $options = new StreamOptions(delivery: 'iterator', storeEnabled: true);
    $session = new StreamSession(
        streamId: 'stream-retention',
        executionId: 'exec-retention',
        options: $options,
        startedAtUnix: time()
    );

    $factory = new StreamSinkFactory(
        Mockery::mock(Broadcaster::class),
        $store,
        false,
        100,
        true,
        new RetentionPolicyFactory,
        new StreamObserverFactory,
        new StreamErrorHandler,
        null
    );
    $wrapped = $factory->wrap($baseSink, $options, $session);

    $event = new StreamEventImpl(
        streamId: 'stream-retention',
        executionId: 'exec-retention',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'b'],
        seq: 2,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('now')
    );

    $wrapped->emit($event);
    $wrapped->close(StreamStatus::SUCCESS);

    expect($truncateCalls)->toBe([['stream-retention', 2]]);
    Event::assertDispatched(StreamStatsUpdated::class, function (StreamStatsUpdated $event) {
        return $event->streamId === 'stream-retention'
            && $event->executionId === 'exec-retention'
            && $event->metrics->eventsEmitted === 1
            && $event->metrics->lastSeq === 2;
    });
});

test('wrapped sink applies retention metrics and error handling in order', function () {
    $order = [];
    $store = new class($order) implements StreamStoreInterface
    {
        public function __construct(private array &$order) {}

        public function append(StreamEvent $event): void
        {
            $this->order[] = 'append';
        }

        public function readFrom(string $streamId, int $fromSeq, int $limit = 200): Generator
        {
            if (false) {
                yield $streamId;
            }
        }

        public function lastSeq(string $streamId): ?int
        {
            return null;
        }

        public function truncate(string $streamId, int $beforeSeq): void
        {
            $this->order[] = 'truncate';
        }
    };
    config()->set('agenticgraph.streaming.retention.max_events_per_stream', 2);
    config()->set('agenticgraph.streaming.retention.max_age_hours', 0);

    $baseSink = new class($order) implements StreamSinkInterface
    {
        public array $events = [];

        public ?StreamStatus $status = null;

        public function __construct(private array &$order) {}

        public function emit(StreamEvent $event): void
        {
            $this->order[] = 'emit';
            $this->events[] = $event;
        }

        public function close(StreamStatus $status, mixed $meta = null): void
        {
            $this->order[] = 'close';
            $this->status = $status;
        }
    };

    $options = new StreamOptions(delivery: 'iterator', storeEnabled: true);
    $session = new StreamSession(
        streamId: 'stream-1',
        executionId: 'exec-1',
        options: $options,
        startedAtUnix: time()
    );
    $factory = new StreamSinkFactory(
        Mockery::mock(Broadcaster::class),
        $store,
        false,
        100,
        true,
        new RetentionPolicyFactory,
        new StreamObserverFactory,
        new StreamErrorHandler,
        null
    );
    $wrapped = $factory->wrap($baseSink, $options, $session);

    Event::listen(StreamStatsUpdated::class, function () use (&$order) {
        $order[] = 'metrics';
    });

    $wrapped->emit(new StreamEventImpl(
        streamId: 'stream-1',
        executionId: 'exec-1',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'a'],
        seq: 5,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('now')
    ));
    $wrapped->close(StreamStatus::SUCCESS);

    $appendIndex = array_search('append', $order, true);
    $truncateIndex = array_search('truncate', $order, true);
    $emitIndex = array_search('emit', $order, true);
    $closeIndex = array_search('close', $order, true);
    $metricsIndex = array_search('metrics', $order, true);

    expect($appendIndex)->toBeLessThan($truncateIndex)
        ->and($truncateIndex)->toBeLessThan($emitIndex)
        ->and($closeIndex)->toBeLessThan($metricsIndex);

    $order = [];
    $store = new class($order) implements StreamStoreInterface
    {
        public function __construct(private array &$order) {}

        public function append(StreamEvent $event): void
        {
            $this->order[] = 'append';
            throw new RuntimeException('Store failure');
        }

        public function readFrom(string $streamId, int $fromSeq, int $limit = 200): Generator
        {
            if (false) {
                yield $streamId;
            }
        }

        public function lastSeq(string $streamId): ?int
        {
            return null;
        }

        public function truncate(string $streamId, int $beforeSeq): void
        {
            $this->order[] = 'truncate';
        }
    };
    $factory = new StreamSinkFactory(
        Mockery::mock(Broadcaster::class),
        $store,
        false,
        100,
        false,
        new RetentionPolicyFactory,
        new StreamObserverFactory,
        new StreamErrorHandler,
        null
    );
    $baseSink = new class($order) implements StreamSinkInterface
    {
        public array $events = [];

        public ?StreamStatus $status = null;

        public function __construct(private array &$order) {}

        public function emit(StreamEvent $event): void
        {
            $this->order[] = 'emit';
            $this->events[] = $event;
        }

        public function close(StreamStatus $status, mixed $meta = null): void
        {
            $this->order[] = 'close';
            $this->status = $status;
        }
    };
    $session = new StreamSession(
        streamId: 'stream-2',
        executionId: 'exec-2',
        options: $options,
        startedAtUnix: time()
    );
    $wrapped = $factory->wrap($baseSink, $options, $session);

    try {
        $wrapped->emit(new StreamEventImpl(
            streamId: 'stream-2',
            executionId: 'exec-2',
            nodeName: 'node-b',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => 'b'],
            seq: 1,
            nodeSeq: 1,
            occurredAt: new DateTimeImmutable('now')
        ));
    } catch (RuntimeException) {
    }

    expect($baseSink->events)->toHaveCount(2)
        ->and($baseSink->events[0]->type())->toBe('stream_error')
        ->and($baseSink->events[1]->type())->toBe('text_delta');

    $appendIndex = array_search('append', $order, true);
    $emitIndices = array_keys(array_filter($order, fn (string $value): bool => $value === 'emit'));

    expect($appendIndex)->toBeLessThan($emitIndices[0])
        ->and($emitIndices[0])->toBeLessThan($emitIndices[1])
        ->and(array_search('close', $order, true))->toBeFalse();
});

test('wrapped sink preserves broadcast sink behavior', function () {
    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->once()->with(
        ['agenticgraph.stream.stream-broadcast'],
        'text_delta',
        Mockery::on(function ($payload) {
            return $payload['stream_id'] === 'stream-broadcast'
                && $payload['execution_id'] === 'exec-broadcast'
                && $payload['seq'] === 1
                && ! array_key_exists('type', $payload);
        })
    );
    $broadcaster->shouldReceive('broadcast')->once()->with(
        ['agenticgraph.stream.stream-broadcast'],
        'stream_close',
        StreamEventEnvelope::streamCloseWirePayload(StreamStatus::SUCCESS, null)
    );
    $options = new StreamOptions(delivery: 'broadcast', storeEnabled: false);
    $session = new StreamSession(
        streamId: 'stream-broadcast',
        executionId: 'exec-broadcast',
        options: $options,
        startedAtUnix: time()
    );

    $factory = new StreamSinkFactory(
        $broadcaster,
        Mockery::mock(StreamStoreInterface::class),
        false,
        100,
        false,
        new RetentionPolicyFactory,
        new StreamObserverFactory,
        new StreamErrorHandler,
        null
    );
    $baseSink = $factory->create($session, $options);
    $wrapped = $factory->wrap($baseSink, $options, $session);

    $event = new StreamEventImpl(
        streamId: 'stream-broadcast',
        executionId: 'exec-broadcast',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'hi'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('now')
    );

    $wrapped->emit($event);
    $wrapped->close(StreamStatus::SUCCESS);
});

test('wrapped sink with sse delivery uses null transport sink', function () {
    $options = new StreamOptions(delivery: 'sse', storeEnabled: false);
    $session = new StreamSession(
        streamId: 'stream-sse',
        executionId: 'exec-sse',
        options: $options,
        startedAtUnix: time()
    );

    $factory = new StreamSinkFactory(
        Mockery::mock(Broadcaster::class),
        Mockery::mock(StreamStoreInterface::class),
        false,
        100,
        false,
        new RetentionPolicyFactory,
        new StreamObserverFactory,
        new StreamErrorHandler
    );
    $baseSink = $factory->create($session, $options);
    expect($baseSink)->toBeInstanceOf(NullTransportSink::class);

    $wrapped = $factory->wrap($baseSink, $options, $session);

    $event = new StreamEventImpl(
        streamId: 'stream-sse',
        executionId: 'exec-sse',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'hi'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('now')
    );

    $wrapped->emit($event);
    $wrapped->close(StreamStatus::SUCCESS);
});

test('wrapped sink preserves memory sink behavior', function () {
    $options = new StreamOptions(delivery: 'iterator', storeEnabled: false);
    $session = new StreamSession(
        streamId: 'stream-memory',
        executionId: 'exec-memory',
        options: $options,
        startedAtUnix: time()
    );

    $factory = new StreamSinkFactory(
        Mockery::mock(Broadcaster::class),
        Mockery::mock(StreamStoreInterface::class),
        false,
        100,
        false,
        new RetentionPolicyFactory,
        new StreamObserverFactory,
        new StreamErrorHandler,
        null
    );
    $baseSink = $factory->create($session, $options);
    $wrapped = $factory->wrap($baseSink, $options, $session);

    $event = new StreamEventImpl(
        streamId: 'stream-memory',
        executionId: 'exec-memory',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'hi'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('now')
    );

    $wrapped->emit($event);
    $wrapped->close(StreamStatus::SUCCESS);

    expect($baseSink->events()->count())->toBe(1)
        ->and($baseSink->status()?->value)->toBe('success');
});

test('wrapped stream sink emits when no filters set', function () {
    Event::fake([StreamStatsUpdated::class]);
    $sink = new MemorySink;
    $observer = new StreamObserver(new StreamMetrics, 'stream-emit', 'exec-emit', (float) time());
    $wrapped = new WrappedStreamSink(
        $sink,
        null,
        null,
        null,
        null,
        $observer,
        new StreamErrorHandler,
        null
    );

    $wrapped->emit(new StreamEventImpl(
        streamId: 'stream-emit',
        executionId: 'exec-emit',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'ok'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('now')
    ));

    expect($sink->events()->count())->toBe(1);
});

test('wrapped stream sink blocks by node filter', function () {
    Event::fake([StreamStatsUpdated::class]);
    $sink = new MemorySink;
    $observer = new StreamObserver(new StreamMetrics, 'stream-node', 'exec-node', (float) time());
    $wrapped = new WrappedStreamSink(
        $sink,
        null,
        ['node-b'],
        null,
        null,
        $observer,
        new StreamErrorHandler,
        null
    );

    $wrapped->emit(new StreamEventImpl(
        streamId: 'stream-node',
        executionId: 'exec-node',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'blocked'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('now')
    ));

    expect($sink->events()->count())->toBe(0);
});

test('wrapped stream sink blocks by event type filter', function () {
    Event::fake([StreamStatsUpdated::class]);
    $sink = new MemorySink;
    $observer = new StreamObserver(new StreamMetrics, 'stream-type', 'exec-type', (float) time());
    $wrapped = new WrappedStreamSink(
        $sink,
        null,
        null,
        ['other_event'],
        null,
        $observer,
        new StreamErrorHandler,
        null
    );

    $wrapped->emit(new StreamEventImpl(
        streamId: 'stream-type',
        executionId: 'exec-type',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'blocked'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('now')
    ));

    expect($sink->events()->count())->toBe(0);
});

test('wrapped stream sink handles nonfatal store failure without rethrowing', function () {
    Event::fake([StreamStatsUpdated::class]);
    $store = new class implements StreamStoreInterface
    {
        public function append(StreamEvent $event): void
        {
            throw new RuntimeException('Store failure');
        }

        public function readFrom(string $streamId, int $fromSeq, int $limit = 200): Generator
        {
            if (false) {
                yield $streamId;
            }
        }

        public function lastSeq(string $streamId): ?int
        {
            return null;
        }

        public function truncate(string $streamId, int $beforeSeq): void {}
    };
    $sink = new MemorySink;
    $observer = new StreamObserver(new StreamMetrics, 'stream-store', 'exec-store', (float) time());
    $errorHandler = new class extends StreamErrorHandler
    {
        public function decide(Throwable $error, StreamEvent $event, string $stage): StreamErrorDecision
        {
            return new StreamErrorDecision(false, new StreamErrorPayload(
                errorType: $error::class,
                message: $error->getMessage(),
                stage: $stage,
                eventType: $event->type(),
                eventSeq: $event->seq(),
                eventNodeName: $event->nodeName(),
                eventNodeSeq: $event->nodeSeq()
            ));
        }
    };
    $wrapped = new WrappedStreamSink(
        $sink,
        $store,
        null,
        null,
        null,
        $observer,
        $errorHandler,
        null
    );

    $wrapped->emit(new StreamEventImpl(
        streamId: 'stream-store',
        executionId: 'exec-store',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'x'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('now')
    ));

    expect($sink->events()->count())->toBe(2)
        ->and($sink->events()->offsetGet(0)->type())->toBe('stream_error')
        ->and($sink->events()->offsetGet(1)->type())->toBe('text_delta');
});

test('wrapped stream sink handles fatal store failure by rethrowing', function () {
    Event::fake([StreamStatsUpdated::class]);
    $store = new class implements StreamStoreInterface
    {
        public function append(StreamEvent $event): void
        {
            throw new RuntimeException('Store failure');
        }

        public function readFrom(string $streamId, int $fromSeq, int $limit = 200): Generator
        {
            if (false) {
                yield $streamId;
            }
        }

        public function lastSeq(string $streamId): ?int
        {
            return null;
        }

        public function truncate(string $streamId, int $beforeSeq): void {}
    };
    $sink = new MemorySink;
    $observer = new StreamObserver(new StreamMetrics, 'stream-store-fatal', 'exec-store-fatal', (float) time());
    $errorHandler = new class extends StreamErrorHandler
    {
        public function decide(Throwable $error, StreamEvent $event, string $stage): StreamErrorDecision
        {
            return new StreamErrorDecision(true, new StreamErrorPayload(
                errorType: $error::class,
                message: $error->getMessage(),
                stage: $stage,
                eventType: $event->type(),
                eventSeq: $event->seq(),
                eventNodeName: $event->nodeName(),
                eventNodeSeq: $event->nodeSeq()
            ));
        }
    };
    $wrapped = new WrappedStreamSink(
        $sink,
        $store,
        null,
        null,
        null,
        $observer,
        $errorHandler,
        null
    );

    try {
        $wrapped->emit(new StreamEventImpl(
            streamId: 'stream-store-fatal',
            executionId: 'exec-store-fatal',
            nodeName: 'node-a',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => 'x'],
            seq: 1,
            nodeSeq: 1,
            occurredAt: new DateTimeImmutable('now')
        ));
    } catch (RuntimeException) {
    }

    expect($sink->status()?->value)->toBe('error');
});

test('wrapped stream sink handles sink failure correctly', function () {
    Event::fake([StreamStatsUpdated::class]);
    $sink = new class implements StreamSinkInterface
    {
        public array $events = [];

        public ?StreamStatus $status = null;

        public function emit(StreamEvent $event): void
        {
            if ($event->type() !== 'stream_error') {
                throw new RuntimeException('Sink failure');
            }
            $this->events[] = $event;
        }

        public function close(StreamStatus $status, mixed $meta = null): void
        {
            $this->status = $status;
        }
    };
    $observer = new StreamObserver(new StreamMetrics, 'stream-sink', 'exec-sink', (float) time());
    $errorHandler = new class extends StreamErrorHandler
    {
        public function decide(Throwable $error, StreamEvent $event, string $stage): StreamErrorDecision
        {
            return new StreamErrorDecision(false, new StreamErrorPayload(
                errorType: $error::class,
                message: $error->getMessage(),
                stage: $stage,
                eventType: $event->type(),
                eventSeq: $event->seq(),
                eventNodeName: $event->nodeName(),
                eventNodeSeq: $event->nodeSeq()
            ));
        }
    };
    $wrapped = new WrappedStreamSink(
        $sink,
        null,
        null,
        null,
        null,
        $observer,
        $errorHandler,
        null
    );

    $wrapped->emit(new StreamEventImpl(
        streamId: 'stream-sink',
        executionId: 'exec-sink',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'x'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('now')
    ));

    expect($sink->events)->toHaveCount(1)
        ->and($sink->events[0]->type())->toBe('stream_error');
});

test('wrapped stream sink flushes observer on close', function () {
    Event::fake([StreamStatsUpdated::class]);
    $sink = new MemorySink;
    $observer = new StreamObserver(new StreamMetrics, 'stream-close', 'exec-close', (float) time());
    $wrapped = new WrappedStreamSink(
        $sink,
        null,
        null,
        null,
        null,
        $observer,
        new StreamErrorHandler,
        null
    );

    $wrapped->close(StreamStatus::SUCCESS);

    expect($sink->status()?->value)->toBe('success');
    Event::assertDispatched(StreamStatsUpdated::class, function (StreamStatsUpdated $event) {
        return $event->streamId === 'stream-close' && $event->executionId === 'exec-close';
    });
});
