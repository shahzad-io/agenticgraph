<?php

use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\Sinks\RateLimitedSink;
use Shahzad\AgenticGraph\Streaming\StreamClock;
use Shahzad\AgenticGraph\Streaming\StreamErrorPayload;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Streaming\StreamStatus;

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

        public function readFrom(string $streamId, int $fromSeq, int $limit = 200): \Generator
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
    app()->instance(StreamStoreInterface::class, $store);

    $baseSink = new MemorySink;
    $options = new StreamOptions(delivery: 'iterator', storeEnabled: true);
    $session = new StreamSession(
        streamId: 'stream-1',
        executionId: 'exec-1',
        options: $options,
        startedAtUnix: time()
    );

    $executor = new GraphExecutor;
    $method = new ReflectionMethod(GraphExecutor::class, 'wrapStreamSink');
    $method->setAccessible(true);
    $wrapped = $method->invoke($executor, $baseSink, $options, $session);

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

        public function readFrom(string $streamId, int $fromSeq, int $limit = 200): \Generator
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
    app()->instance(StreamStoreInterface::class, $store);

    $baseSink = new MemorySink;
    $options = new StreamOptions(delivery: 'iterator', storeEnabled: true);
    $session = new StreamSession(
        streamId: 'stream-2',
        executionId: 'exec-2',
        options: $options,
        startedAtUnix: time()
    );

    $executor = new GraphExecutor;
    $method = new ReflectionMethod(GraphExecutor::class, 'wrapStreamSink');
    $method->setAccessible(true);
    $wrapped = $method->invoke($executor, $baseSink, $options, $session);

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

    expect($events)->toHaveCount(1)
        ->and($events[0]->type())->toBe('stream_error')
        ->and($payload)->toBeInstanceOf(StreamErrorPayload::class)
        ->and($payload->stage)->toBe('store')
        ->and($baseSink->status()?->value)->toBe('error');
});

test('wrapped sink handles client disconnect mid-stream', function () {
    $baseSink = new class implements \Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface
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

    $executor = new GraphExecutor;
    $method = new ReflectionMethod(GraphExecutor::class, 'wrapStreamSink');
    $method->setAccessible(true);
    $wrapped = $method->invoke($executor, $baseSink, $options, $session);

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
        ->and($payload->stage)->toBe('emit')
        ->and($baseSink->status?->value)->toBe('error');
});
