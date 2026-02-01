<?php

use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Events\BatchableEventInterface;
use Shahzad\AgenticGraph\Events\BatchedEventDispatcher;

// Mock batchable event for testing
class TestBatchableEvent implements BatchableEventInterface
{
    public function __construct(
        public readonly string $key,
        public readonly int $value
    ) {}

    public function getBatchKey(): string
    {
        return "test:{$this->key}";
    }

    public function merge(BatchableEventInterface $other): self
    {
        if (! $other instanceof self) {
            throw new \InvalidArgumentException('Cannot merge with different event type');
        }

        return new self(
            key: $this->key,
            value: $this->value + $other->value
        );
    }
}

// Non-batchable event for testing
class TestRegularEvent
{
    public function __construct(public readonly string $data) {}
}

beforeEach(function () {
    $this->events = Mockery::mock(Dispatcher::class);
    $this->logger = Mockery::mock(LoggerInterface::class);
    $this->dispatcher = new BatchedEventDispatcher(
        $this->events,
        $this->logger,
        batchSize: 3,
        flushIntervalSeconds: 10,
        enabled: true
    );
});

afterEach(function () {
    Mockery::close();
});

describe('batching behavior', function () {

    it('buffers batchable events', function () {
        $event1 = new TestBatchableEvent('a', 1);
        $event2 = new TestBatchableEvent('a', 2);

        $this->dispatcher->dispatch($event1);
        $this->dispatcher->dispatch($event2);

        expect($this->dispatcher->getBufferedCount())->toBe(1);

        $buffer = $this->dispatcher->getBuffer();
        expect($buffer['test:a']->value)->toBe(3); // 1 + 2 merged
    });

    it('flushes when batch size reached', function () {
        $dispatchedCount = 0;
        $this->events->shouldReceive('dispatch')->andReturnUsing(function () use (&$dispatchedCount) {
            $dispatchedCount++;
        });
        $this->logger->shouldReceive('debug')->once();

        // Dispatch 3 events with different keys to fill buffer
        $this->dispatcher->dispatch(new TestBatchableEvent('a', 1));
        $this->dispatcher->dispatch(new TestBatchableEvent('b', 2));

        expect($dispatchedCount)->toBe(0)
            ->and($this->dispatcher->getBufferedCount())->toBe(2);

        // Third event should trigger flush
        $this->dispatcher->dispatch(new TestBatchableEvent('c', 3));

        expect($dispatchedCount)->toBe(3)
            ->and($this->dispatcher->getBufferedCount())->toBe(0);
    });

    it('flushes manually', function () {
        $this->events->shouldReceive('dispatch')->twice();
        $this->logger->shouldReceive('debug')->once();

        $this->dispatcher->dispatch(new TestBatchableEvent('a', 1));
        $this->dispatcher->dispatch(new TestBatchableEvent('b', 2));

        expect($this->dispatcher->getBufferedCount())->toBe(2);

        $this->dispatcher->flush();

        expect($this->dispatcher->getBufferedCount())->toBe(0);
    });

    it('dispatches non-batchable events immediately', function () {
        $this->events->shouldReceive('dispatch')->once()->with(Mockery::type(TestRegularEvent::class));

        $this->dispatcher->dispatch(new TestRegularEvent('immediate'));

        expect($this->dispatcher->getBufferedCount())->toBe(0);
    });

    it('clears buffer without dispatching', function () {
        $this->dispatcher->dispatch(new TestBatchableEvent('a', 1));
        $this->dispatcher->dispatch(new TestBatchableEvent('b', 2));

        expect($this->dispatcher->getBufferedCount())->toBe(2);

        $this->dispatcher->clearBuffer();

        expect($this->dispatcher->getBufferedCount())->toBe(0);
    });

});

describe('configuration', function () {

    it('can be disabled', function () {
        $disabledDispatcher = new BatchedEventDispatcher(
            $this->events,
            null,
            enabled: false
        );

        // Even batchable events should dispatch immediately when disabled
        $this->events->shouldReceive('dispatch')->once();

        $disabledDispatcher->dispatch(new TestBatchableEvent('a', 1));

        expect($disabledDispatcher->getBufferedCount())->toBe(0);
    });

    it('can enable and disable dynamically', function () {
        // Start enabled
        $this->dispatcher->dispatch(new TestBatchableEvent('a', 1));
        expect($this->dispatcher->getBufferedCount())->toBe(1);

        // Disable flushes pending events
        $this->events->shouldReceive('dispatch')->once();
        $this->logger->shouldReceive('debug')->once();
        $this->dispatcher->setEnabled(false);
        expect($this->dispatcher->getBufferedCount())->toBe(0);

        // Now events dispatch immediately
        $this->events->shouldReceive('dispatch')->once();
        $this->dispatcher->dispatch(new TestBatchableEvent('b', 2));
        expect($this->dispatcher->getBufferedCount())->toBe(0);
    });

    it('can change batch size dynamically', function () {
        $this->events->shouldReceive('dispatch')->twice();
        $this->logger->shouldReceive('debug')->once();

        $this->dispatcher->dispatch(new TestBatchableEvent('a', 1));
        $this->dispatcher->dispatch(new TestBatchableEvent('b', 2));

        // Reduce batch size to 2, should trigger flush
        $this->dispatcher->setBatchSize(2);

        expect($this->dispatcher->getBufferedCount())->toBe(0);
    });

    it('can change flush interval dynamically', function () {
        $this->events->shouldReceive('dispatch')->twice();
        $this->logger->shouldReceive('debug')->twice();

        $this->dispatcher->dispatch(new TestBatchableEvent('a', 1));

        // Set interval to 0, should trigger immediate flush on next check
        $this->dispatcher->setFlushInterval(0);

        // Trigger a flush check by dispatching another event
        $this->dispatcher->dispatch(new TestBatchableEvent('b', 2));
    });

    it('returns configuration values', function () {
        expect($this->dispatcher->getBatchSize())->toBe(3)
            ->and($this->dispatcher->getFlushInterval())->toBe(10.0)
            ->and($this->dispatcher->isEnabled())->toBeTrue();
    });

});

describe('event merging', function () {

    it('merges events with same batch key', function () {
        $event1 = new TestBatchableEvent('shared', 10);
        $event2 = new TestBatchableEvent('shared', 20);
        $event3 = new TestBatchableEvent('shared', 30);

        $this->dispatcher->dispatch($event1);
        $this->dispatcher->dispatch($event2);
        $this->dispatcher->dispatch($event3);

        $buffer = $this->dispatcher->getBuffer();
        expect($buffer['test:shared']->value)->toBe(60); // 10 + 20 + 30
    });

    it('keeps separate events with different batch keys', function () {
        $event1 = new TestBatchableEvent('key1', 10);
        $event2 = new TestBatchableEvent('key2', 20);

        $this->dispatcher->dispatch($event1);
        $this->dispatcher->dispatch($event2);

        $buffer = $this->dispatcher->getBuffer();
        expect($buffer)->toHaveCount(2)
            ->and($buffer['test:key1']->value)->toBe(10)
            ->and($buffer['test:key2']->value)->toBe(20);
    });

    it('throws when merging incompatible event types', function () {
        // Create a second event type
        $event1 = new TestBatchableEvent('key', 10);

        // Create incompatible mock
        $incompatible = Mockery::mock(BatchableEventInterface::class);
        $incompatible->shouldReceive('getBatchKey')->andReturn('test:key');
        $incompatible->shouldReceive('merge')->andThrow(new \InvalidArgumentException('Incompatible types'));

        $this->dispatcher->dispatch($event1);

        expect(fn () => $this->dispatcher->dispatch($incompatible))
            ->toThrow(\InvalidArgumentException::class);
    });

});

describe('logging', function () {

    it('logs on flush when logger provided', function () {
        config(['agenticgraph.logging.enabled' => true]);

        $this->events->shouldReceive('dispatch');
        $this->logger->shouldReceive('debug')->once()->with('BatchedEventDispatcher flushed events', Mockery::any());

        $this->dispatcher->dispatch(new TestBatchableEvent('a', 1));
        $this->dispatcher->flush();
    });

    it('does not log when logging disabled', function () {
        config(['agenticgraph.logging.enabled' => false]);

        $this->events->shouldReceive('dispatch');
        $this->logger->shouldNotReceive('debug');

        $this->dispatcher->dispatch(new TestBatchableEvent('a', 1));
        $this->dispatcher->flush();
    });

});
