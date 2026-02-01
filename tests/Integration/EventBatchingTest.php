<?php

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Shahzad\AgenticGraph\Events\BatchableEventInterface;
use Shahzad\AgenticGraph\Events\BatchedEventDispatcher;
use Shahzad\AgenticGraph\Events\TokensUsed;

uses()->group('integration');

beforeEach(function () {
    // Fake the TokensUsed event and queue BEFORE creating dispatcher
    Event::fake([TokensUsed::class]);
    Queue::fake();

    // Get the faked event dispatcher
    $this->events = Event::getFacadeRoot();

    // Create dispatcher with small batch size for testing
    $this->dispatcher = new BatchedEventDispatcher(
        $this->events,
        null,
        batchSize: 3,
        flushIntervalSeconds: 60, // Long interval to test size-based flushing
        enabled: true
    );
});

describe('event batching integration', function () {

    it('batches tokens used events', function () {
        // Create dispatcher with mock to capture events
        $capturedEvents = [];
        $mockDispatcher = Mockery::mock(Dispatcher::class);
        $mockDispatcher->shouldReceive('dispatch')->andReturnUsing(function ($event) use (&$capturedEvents) {
            $capturedEvents[] = $event;
        });

        $dispatcher = new BatchedEventDispatcher(
            $mockDispatcher,
            null,
            batchSize: 3,
            flushIntervalSeconds: 60,
            enabled: true
        );

        // Dispatch multiple TokensUsed events with DIFFERENT batch keys
        // to fill up the buffer (each has unique key so no merging)
        $dispatcher->dispatch(new TokensUsed(
            node: 'test-node-1',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
            callType: 'text',
            model: 'openai:gpt-4',
            durationMs: 100.0,
            endedAt: new DateTimeImmutable
        ));
        $dispatcher->dispatch(new TokensUsed(
            node: 'test-node-2',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
            callType: 'text',
            model: 'openai:gpt-4',
            durationMs: 100.0,
            endedAt: new DateTimeImmutable
        ));
        $dispatcher->dispatch(new TokensUsed(
            node: 'test-node-3',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
            callType: 'text',
            model: 'openai:gpt-4',
            durationMs: 100.0,
            endedAt: new DateTimeImmutable
        ));

        // Should have flushed after 3 events (batch size reached)
        expect($capturedEvents)->toHaveCount(3);
    });

    it('merges events with same batch key', function () {
        // Create custom dispatcher to capture merged events
        $capturedEvents = [];
        $mockDispatcher = Mockery::mock(Dispatcher::class);
        $mockDispatcher->shouldReceive('dispatch')->andReturnUsing(function ($event) use (&$capturedEvents) {
            $capturedEvents[] = $event;
        });

        $dispatcher = new BatchedEventDispatcher(
            $mockDispatcher,
            null,
            batchSize: 5,
            flushIntervalSeconds: 60,
            enabled: true
        );

        // Dispatch multiple events with same node (they merge into 1 buffer entry)
        for ($i = 0; $i < 5; $i++) {
            $dispatcher->dispatch(new TokensUsed(
                node: 'same-node',
                promptTokens: 10,
                completionTokens: 5,
                totalTokens: 15,
                callType: 'text',
                model: 'openai:gpt-4',
                durationMs: 100.0,
                endedAt: new DateTimeImmutable
            ));
        }

        // Events with same batch key are merged, so buffer has 1 entry
        // Need to manually flush since batch size is 5 but we only have 1 entry
        $dispatcher->flush();

        // Should have 1 merged event with aggregated values
        expect($capturedEvents)->toHaveCount(1);
        expect($capturedEvents[0]->promptTokens)->toBe(50); // 10 * 5
        expect($capturedEvents[0]->completionTokens)->toBe(25); // 5 * 5
        expect($capturedEvents[0]->totalTokens)->toBe(75); // 15 * 5
    });

    it('keeps separate events with different batch keys', function () {
        $capturedEvents = [];
        $mockDispatcher = Mockery::mock(Dispatcher::class);
        $mockDispatcher->shouldReceive('dispatch')->andReturnUsing(function ($event) use (&$capturedEvents) {
            $capturedEvents[] = $event;
        });

        $dispatcher = new BatchedEventDispatcher(
            $mockDispatcher,
            null,
            batchSize: 4,
            flushIntervalSeconds: 60,
            enabled: true
        );

        // Dispatch events with different nodes
        $dispatcher->dispatch(new TokensUsed(node: 'node-a', promptTokens: 10, completionTokens: 5, totalTokens: 15, callType: 'text'));
        $dispatcher->dispatch(new TokensUsed(node: 'node-b', promptTokens: 20, completionTokens: 10, totalTokens: 30, callType: 'text'));
        $dispatcher->dispatch(new TokensUsed(node: 'node-a', promptTokens: 10, completionTokens: 5, totalTokens: 15, callType: 'text'));
        $dispatcher->dispatch(new TokensUsed(node: 'node-b', promptTokens: 20, completionTokens: 10, totalTokens: 30, callType: 'text'));

        // Buffer has 2 entries (node-a and node-b, merged within each)
        // Batch size is 4, so need to flush manually
        $dispatcher->flush();

        // Should have 2 merged events (one per node)
        expect($capturedEvents)->toHaveCount(2);

        $nodeAEvent = collect($capturedEvents)->first(fn ($e) => $e->node === 'node-a');
        $nodeBEvent = collect($capturedEvents)->first(fn ($e) => $e->node === 'node-b');

        expect($nodeAEvent->promptTokens)->toBe(20);
        expect($nodeBEvent->promptTokens)->toBe(40);
    });

    it('dispatches non-batchable events immediately', function () {
        $capturedEvents = [];
        $mockDispatcher = Mockery::mock(Dispatcher::class);
        $mockDispatcher->shouldReceive('dispatch')->andReturnUsing(function ($event) use (&$capturedEvents) {
            $capturedEvents[] = $event;
        });

        $dispatcher = new BatchedEventDispatcher(
            $mockDispatcher,
            null,
            batchSize: 10,
            flushIntervalSeconds: 60,
            enabled: true
        );

        // Dispatch a non-batchable event
        $regularEvent = new class
        {
            public $data = 'test';
        };

        $dispatcher->dispatch($regularEvent);

        // Should be dispatched immediately
        expect($capturedEvents)->toHaveCount(1)
            ->and($capturedEvents[0])->toBe($regularEvent);
    });

    it('flushes manually', function () {
        $capturedEvents = [];
        $mockDispatcher = Mockery::mock(Dispatcher::class);
        $mockDispatcher->shouldReceive('dispatch')->andReturnUsing(function ($event) use (&$capturedEvents) {
            $capturedEvents[] = $event;
        });

        $dispatcher = new BatchedEventDispatcher(
            $mockDispatcher,
            null,
            batchSize: 100, // Large batch size
            flushIntervalSeconds: 60,
            enabled: true
        );

        // Dispatch some events
        $dispatcher->dispatch(new TokensUsed(node: 'node-a', promptTokens: 10, completionTokens: 5, totalTokens: 15, callType: 'text'));
        $dispatcher->dispatch(new TokensUsed(node: 'node-b', promptTokens: 20, completionTokens: 10, totalTokens: 30, callType: 'text'));

        expect($capturedEvents)->toHaveCount(0);

        // Manual flush
        $dispatcher->flush();

        expect($capturedEvents)->toHaveCount(2);
    });

    it('clears buffer without dispatching', function () {
        $mockDispatcher = Mockery::mock(Dispatcher::class);
        $mockDispatcher->shouldNotReceive('dispatch');

        $dispatcher = new BatchedEventDispatcher(
            $mockDispatcher,
            null,
            batchSize: 100,
            flushIntervalSeconds: 60,
            enabled: true
        );

        $dispatcher->dispatch(new TokensUsed(node: 'node-a', promptTokens: 10, completionTokens: 5, totalTokens: 15, callType: 'text'));
        expect($dispatcher->getBufferedCount())->toBe(1);

        $dispatcher->clearBuffer();
        expect($dispatcher->getBufferedCount())->toBe(0);
    });

    it('disables and flushes pending events', function () {
        $capturedEvents = [];
        $mockDispatcher = Mockery::mock(Dispatcher::class);
        $mockDispatcher->shouldReceive('dispatch')->andReturnUsing(function ($event) use (&$capturedEvents) {
            $capturedEvents[] = $event;
        });

        $dispatcher = new BatchedEventDispatcher(
            $mockDispatcher,
            null,
            batchSize: 100,
            flushIntervalSeconds: 60,
            enabled: true
        );

        $dispatcher->dispatch(new TokensUsed(node: 'node-a', promptTokens: 10, completionTokens: 5, totalTokens: 15, callType: 'text'));
        expect($dispatcher->isEnabled())->toBeTrue();

        // Disable should flush
        $dispatcher->setEnabled(false);

        expect($dispatcher->isEnabled())->toBeFalse()
            ->and($capturedEvents)->toHaveCount(1);
    });

    it('dispatches immediately when disabled', function () {
        $capturedEvents = [];
        $mockDispatcher = Mockery::mock(Dispatcher::class);
        $mockDispatcher->shouldReceive('dispatch')->andReturnUsing(function ($event) use (&$capturedEvents) {
            $capturedEvents[] = $event;
        });

        $dispatcher = new BatchedEventDispatcher(
            $mockDispatcher,
            null,
            batchSize: 100,
            flushIntervalSeconds: 60,
            enabled: false // Start disabled
        );

        $dispatcher->dispatch(new TokensUsed(node: 'node-a', promptTokens: 10, completionTokens: 5, totalTokens: 15, callType: 'text'));

        expect($capturedEvents)->toHaveCount(1);
    });

    it('respects configuration from config file', function () {
        config([
            'agenticgraph.events.batching.enabled' => true,
            'agenticgraph.events.batching.batch_size' => 5,
            'agenticgraph.events.batching.flush_interval_seconds' => 30,
        ]);

        $dispatcher = new BatchedEventDispatcher($this->events);

        expect($dispatcher->isEnabled())->toBeTrue()
            ->and($dispatcher->getBatchSize())->toBe(5)
            ->and($dispatcher->getFlushInterval())->toBe(30.0);
    });

    it('handles mixed batchable and non-batchable events', function () {
        $capturedEvents = [];
        $mockDispatcher = Mockery::mock(Dispatcher::class);
        $mockDispatcher->shouldReceive('dispatch')->andReturnUsing(function ($event) use (&$capturedEvents) {
            $capturedEvents[] = $event;
        });

        $dispatcher = new BatchedEventDispatcher(
            $mockDispatcher,
            null,
            batchSize: 10,
            flushIntervalSeconds: 60,
            enabled: true
        );

        // Mix of events
        $dispatcher->dispatch(new TokensUsed(node: 'node-a', promptTokens: 10, completionTokens: 5, totalTokens: 15, callType: 'text'));
        $dispatcher->dispatch(new class
        {
            public $type = 'immediate';
        });
        $dispatcher->dispatch(new TokensUsed(node: 'node-a', promptTokens: 10, completionTokens: 5, totalTokens: 15, callType: 'text'));

        // Non-batchable should be dispatched immediately
        expect($capturedEvents)->toHaveCount(1)
            ->and($capturedEvents[0]->type)->toBe('immediate');

        // Flush the batchable events
        $dispatcher->flush();

        expect($capturedEvents)->toHaveCount(2);
    });

});

describe('tokens used event batching', function () {

    it('implements batchable interface', function () {
        $event = new TokensUsed(
            node: 'test-node',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
            callType: 'text',
            executionId: 'exec-1'
        );

        expect($event)->toBeInstanceOf(BatchableEventInterface::class);
        expect($event->getBatchKey())->toBe('tokens:exec-1:test-node:text');
    });

    it('merges tokens used events correctly', function () {
        $event1 = new TokensUsed(
            node: 'test-node',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
            executionId: 'exec-1',
            callType: 'text',
            model: 'openai:gpt-4',
            durationMs: 100.0,
            endedAt: new DateTimeImmutable('2024-01-01 10:00:00')
        );

        $event2 = new TokensUsed(
            node: 'test-node',
            promptTokens: 20,
            completionTokens: 10,
            totalTokens: 30,
            executionId: 'exec-1',
            callType: 'text',
            model: 'openai:gpt-4',
            durationMs: 200.0,
            endedAt: new DateTimeImmutable('2024-01-01 10:00:01')
        );

        $merged = $event1->merge($event2);

        expect($merged->promptTokens)->toBe(30)
            ->and($merged->completionTokens)->toBe(15)
            ->and($merged->totalTokens)->toBe(45)
            ->and($merged->durationMs)->toBe(300.0)
            ->and($merged->endedAt)->toEqual($event2->endedAt); // Latest timestamp
    });

    it('throws when merging incompatible event types', function () {
        $tokensEvent = new TokensUsed(
            node: 'node',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
            callType: 'text',
            executionId: 'exec-1'
        );

        $incompatibleEvent = Mockery::mock(BatchableEventInterface::class);
        $incompatibleEvent->shouldReceive('getBatchKey')->andReturn('tokens:node:text');

        expect(fn () => $tokensEvent->merge($incompatibleEvent))
            ->toThrow(InvalidArgumentException::class, 'Cannot merge with different event type');
    });

    it('groups by node and call type', function () {
        $event1 = new TokensUsed(
            node: 'node-a',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
            callType: 'text',
            executionId: 'exec-1'
        );
        $event2 = new TokensUsed(
            node: 'node-a',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
            callType: 'tools',
            executionId: 'exec-1'
        );
        $event3 = new TokensUsed(
            node: 'node-b',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
            callType: 'text',
            executionId: 'exec-2'
        );

        expect($event1->getBatchKey())->toBe('tokens:exec-1:node-a:text')
            ->and($event2->getBatchKey())->toBe('tokens:exec-1:node-a:tools')
            ->and($event3->getBatchKey())->toBe('tokens:exec-2:node-b:text');
    });

});
