<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery as MockeryFacade;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Jobs\ResumeExecutionJob;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamResumption;

class AsyncStreamResumeApprovalNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('processed', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->has('approved');
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Needs approval';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $this->handleApproval(
            $state,
            $humanInput['approved'] ?? false,
            $humanInput['comments'] ?? null
        );
    }
}

class AsyncStreamResumeGateOneNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('gate_one_done', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->has('approved_one');
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Gate one';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $state->set('approved_one', (bool) ($humanInput['approved_one'] ?? false));
    }
}

class AsyncStreamResumeGateTwoNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('gate_two_done', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->has('approved_two');
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Gate two';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $state->set('approved_two', (bool) ($humanInput['approved_two'] ?? false));
    }
}

function registerTwoGateStreamResumeGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('gate_one', AsyncStreamResumeGateOneNode::class)
        ->addNode('gate_two', AsyncStreamResumeGateTwoNode::class)
        ->addEdge('gate_one', 'gate_two')
        ->setEntryPoint('gate_one')
        ->addExitPoint('gate_two')
        ->build();

    AgenticGraph::register($name, $graph);
}

function registerAsyncStreamResumeGraph(string $name, string $nodeClass = AsyncStreamResumeApprovalNode::class): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('approve', $nodeClass)
        ->setEntryPoint('approve')
        ->addExitPoint('approve')
        ->build();

    AgenticGraph::register($name, $graph);
}

function runAsyncExecuteJob(string $executionId): void
{
    $job = null;

    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $dispatched) use ($executionId, &$job): bool {
        if ($dispatched->executionId !== $executionId) {
            return false;
        }

        $job = $dispatched;

        return true;
    });

    expect($job)->toBeInstanceOf(ExecuteGraphJob::class);
    $job->handle(app(GraphExecutor::class), app(GraphRegistry::class));
}

function runAsyncResumeJob(string $executionId): void
{
    $job = null;

    Bus::assertDispatched(ResumeExecutionJob::class, function (ResumeExecutionJob $dispatched) use ($executionId, &$job): bool {
        $interrupt = InterruptRecord::find($dispatched->interruptId);

        if ($interrupt?->executionId() !== $executionId) {
            return false;
        }

        $job = $dispatched;

        return true;
    });

    expect($job)->toBeInstanceOf(ResumeExecutionJob::class);
    $job->handle(
        app(InterruptServiceInterface::class),
        app(StreamResumption::class)
    );
}

/**
 * @return array<int, StreamEvent>
 */
function redisStreamEvents(string $streamId): array
{
    $store = app(StreamStoreInterface::class);

    return iterator_to_array($store->readFrom($streamId, 1, 500));
}

function eventSeqByType(array $events, string $type): ?int
{
    foreach ($events as $event) {
        if ($event->type() === $type) {
            return $event->seq();
        }
    }

    return null;
}

function assertMonotonicSeqs(array $events): void
{
    $seen = [];
    $previous = 0;

    foreach ($events as $event) {
        $seq = $event->seq();
        expect($seen)->not->toHaveKey($seq);
        $seen[$seq] = true;
        expect($seq)->toBeGreaterThan($previous);
        $previous = $seq;
    }
}

beforeEach(function () {
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    app()->forgetInstance(StreamStoreInterface::class);
});

afterEach(function () {
    MockeryFacade::close();
});

it('records stream_resume after async broadcast resume on redis without seq collision', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis is not available');
    }

    config()->set('agenticgraph.streaming.store.driver', 'redis');

    $graphName = 'async-broadcast-resume-redis-'.Str::uuid();
    registerAsyncStreamResumeGraph($graphName);

    $broadcaster = MockeryFacade::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->atLeast()->once();
    app()->instance(Broadcaster::class, $broadcaster);

    Bus::fake();

    $handle = AgenticGraph::execute(
        $graphName,
        new State(['prompt' => 'approve']),
        new ExecutionOptions(
            mode: ExecutionMode::ASYNC,
            streaming: StreamingMode::BROADCAST,
            interrupts: true
        )
    );

    $executionId = $handle->id();
    $streamId = $handle->streamId();
    expect($streamId)->not->toBeNull();

    runAsyncExecuteJob($executionId);

    $afterInterrupt = redisStreamEvents($streamId);
    $interruptSeq = eventSeqByType($afterInterrupt, 'node_interrupt');

    expect($interruptSeq)->not->toBeNull();

    $resumed = $handle->refresh()->resume(['approved' => true, 'comments' => 'ok']);
    expect($resumed->status())->toBe(ExecutionStatus::RUNNING);

    runAsyncResumeJob($executionId);

    $afterResume = redisStreamEvents($streamId);
    $resumeSeq = eventSeqByType($afterResume, 'stream_resume');

    expect($resumeSeq)->not->toBeNull()
        ->and($resumeSeq)->toBeGreaterThan($interruptSeq);

    assertMonotonicSeqs($afterResume);
});

it('keeps replay from interrupt boundary gap-free and monotonic on redis', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis is not available');
    }

    config()->set('agenticgraph.streaming.store.driver', 'redis');

    $graphName = 'async-broadcast-replay-redis-'.Str::uuid();
    registerAsyncStreamResumeGraph($graphName);

    $broadcaster = MockeryFacade::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->atLeast()->once();
    app()->instance(Broadcaster::class, $broadcaster);

    Bus::fake();

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::ASYNC,
            streaming: StreamingMode::BROADCAST,
            interrupts: true
        )
    );

    $executionId = $handle->id();
    $streamId = $handle->streamId();
    expect($streamId)->not->toBeNull();

    runAsyncExecuteJob($executionId);

    $interruptSeq = eventSeqByType(redisStreamEvents($streamId), 'node_interrupt');
    expect($interruptSeq)->not->toBeNull();

    $handle->refresh()->resume(['approved' => true]);
    runAsyncResumeJob($executionId);

    $allEvents = redisStreamEvents($streamId);
    assertMonotonicSeqs($allEvents);

    $fromInterrupt = iterator_to_array(app(StreamStoreInterface::class)->readFrom($streamId, $interruptSeq, 500));
    $fromInterruptSeqs = array_map(fn (StreamEvent $event) => $event->seq(), $fromInterrupt);

    expect($fromInterruptSeqs[0])->toBe($interruptSeq)
        ->and($fromInterruptSeqs)->toContain(eventSeqByType($allEvents, 'stream_resume'));

    $expectedTail = array_values(array_filter(
        array_map(fn (StreamEvent $event) => $event->seq(), $allEvents),
        fn (int $seq) => $seq >= $interruptSeq
    ));
    expect($fromInterruptSeqs)->toBe($expectedTail);
});

it('logs non-fatal store write failures with store entry id instead of vanishing silently', function () {
    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $logged) use (&$warnings): void {
        if ($logged->level === 'warning') {
            $warnings[] = $logged;
        }
    });

    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.streaming.errors.store_fatal', false);

    $store = new class implements StreamStoreInterface
    {
        public function append(StreamEvent $event): void
        {
            throw new RuntimeException('duplicate entry '.$event->seq().'-0');
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

        public function firstSeq(string $streamId): ?int
        {
            return null;
        }

        public function truncate(string $streamId, int $beforeSeq): void {}
    };

    app()->forgetInstance(StreamStoreInterface::class);
    app()->instance(StreamStoreInterface::class, $store);

    $graphName = 'store-drop-logging-'.Str::uuid();
    registerAsyncStreamResumeGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::ITERATOR,
            interrupts: true
        )
    );

    iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));

    $storeWarnings = array_values(array_filter(
        $warnings,
        fn (MessageLogged $logged) => ($logged->context['stage'] ?? null) === 'store'
    ));

    expect($storeWarnings)->not->toBeEmpty()
        ->and($storeWarnings[0]->message)->toBe('Non-fatal stream store write failed')
        ->and($storeWarnings[0]->context['event_seq'] ?? null)->toBeInt()
        ->and($storeWarnings[0]->context['store_entry_id'] ?? null)->toBeString();
});

it('keeps async resume handle running through refresh while job is in flight', function () {
    Bus::fake();

    $graphName = 'async-refresh-running-'.Str::uuid();
    registerAsyncStreamResumeGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::ASYNC,
            streaming: StreamingMode::NONE,
            interrupts: true
        )
    );

    runAsyncExecuteJob($handle->id());

    $handle = $handle->refresh();
    expect($handle->status())->toBe(ExecutionStatus::INTERRUPTED);

    $resumed = $handle->resume(['approved' => true, 'comments' => 'ok']);

    expect($resumed->status())->toBe(ExecutionStatus::RUNNING)
        ->and($resumed->refresh()->status())->toBe(ExecutionStatus::RUNNING);
});

it('reports running for reconstructed handle while interrupt is resolving', function () {
    Bus::fake();

    $graphName = 'async-reconstructed-running-'.Str::uuid();
    registerAsyncStreamResumeGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::ASYNC,
            streaming: StreamingMode::NONE,
            interrupts: true
        )
    );

    runAsyncExecuteJob($handle->id());

    $handle->refresh()->resume(['approved' => true]);

    $reconstructed = AgenticGraph::getExecution($handle->id())->refresh();

    expect($reconstructed->status())->toBe(ExecutionStatus::RUNNING);
});

it('reports interrupted on a subsequent gate once resolving is cleared', function () {
    $graphName = 'sync-second-interrupt-honest-'.Str::uuid();
    registerTwoGateStreamResumeGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::NONE,
            interrupts: true
        )
    );

    expect($handle->status())->toBe(ExecutionStatus::INTERRUPTED)
        ->and($handle->refresh()->status())->toBe(ExecutionStatus::INTERRUPTED);

    $afterFirstResume = $handle->resume(['approved_one' => true]);

    expect($afterFirstResume->status())->toBe(ExecutionStatus::INTERRUPTED)
        ->and($afterFirstResume->refresh()->status())->toBe(ExecutionStatus::INTERRUPTED)
        ->and(InterruptRecord::query()->forExecution($handle->id())->resolving()->exists())->toBeFalse()
        ->and(InterruptRecord::query()->forExecution($handle->id())->pending()->exists())->toBeTrue()
        ->and($afterFirstResume->interruptedAtNode())->toBe('gate_two');
});

it('still emits stream_resume on sync iterator resume with monotonic seqs', function () {
    $graphName = 'sync-iterator-stream-resume-'.Str::uuid();
    registerAsyncStreamResumeGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::ITERATOR,
            interrupts: true
        )
    );

    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
    $interruptSeq = eventSeqByType($events, 'node_interrupt');
    expect($interruptSeq)->not->toBeNull();

    $resumeHandle = $handle->resume(['approved' => true]);
    $resumeEvents = iterator_to_array($resumeHandle->stream()->inline ?? new ArrayIterator([]));
    $resumeSeq = eventSeqByType($resumeEvents, 'stream_resume');

    expect($resumeSeq)->not->toBeNull()
        ->and($resumeSeq)->toBeGreaterThan($interruptSeq);

    $combined = array_merge($events, $resumeEvents);
    assertMonotonicSeqs($combined);
});
