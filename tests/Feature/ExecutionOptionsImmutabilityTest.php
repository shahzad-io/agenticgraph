<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Bus;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

class ImmutabilitySimpleNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('done', true);
    }
}

class ImmutabilityStreamNode extends AbstractNode
{
    protected ?string $name = 'immutability-stream';

    public function execute(StateContract $state): StateContract
    {
        if ($this->isStreaming()) {
            $this->streamEvent('text_delta', ['delta' => 'auto-resolved']);

            return $state->set('streamed', true);
        }

        return $state;
    }
}

function registerImmutabilityGraph(string $name, bool $streaming = false): void
{
    $builder = GraphBuilder::create($name);

    if ($streaming) {
        $builder->addNode('start', ImmutabilityStreamNode::class);
    } else {
        $builder->addNode('start', ImmutabilitySimpleNode::class);
    }

    $graph = $builder
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register($name, $graph);
}

it('withStreaming returns a clone with streaming replaced and other fields preserved', function () {
    $original = new ExecutionOptions(
        mode: ExecutionMode::SYNC,
        streaming: StreamingMode::AUTO,
        interrupts: false,
        estimatedDurationSeconds: 42,
        nodeFilter: ['alpha'],
        eventTypeFilter: ['text_delta'],
    );

    $resolved = $original->withStreaming(StreamingMode::SSE);

    expect($resolved)->not->toBe($original)
        ->and($resolved->streaming)->toBe(StreamingMode::SSE)
        ->and($original->streaming)->toBe(StreamingMode::AUTO)
        ->and($resolved->mode)->toBe(ExecutionMode::SYNC)
        ->and($resolved->interrupts)->toBeFalse()
        ->and($resolved->estimatedDurationSeconds)->toBe(42)
        ->and($resolved->nodeFilter)->toBe(['alpha'])
        ->and($resolved->eventTypeFilter)->toBe(['text_delta']);
});

it('withNodeFilter and withEventTypeFilter return clones without mutating the original', function () {
    $original = new ExecutionOptions(
        mode: ExecutionMode::ASYNC,
        streaming: StreamingMode::SSE,
        nodeFilter: ['keep'],
    );

    $withNodes = $original->withNodeFilter(['alpha', 'beta']);
    $withEvents = $original->withEventTypeFilter(['node_completed']);

    expect($withNodes)->not->toBe($original)
        ->and($withNodes->nodeFilter)->toBe(['alpha', 'beta'])
        ->and($withNodes->eventTypeFilter)->toBeNull()
        ->and($withEvents->eventTypeFilter)->toBe(['node_completed'])
        ->and($original->nodeFilter)->toBe(['keep'])
        ->and($original->eventTypeFilter)->toBeNull();
});

it('resolveStreamingMode returns non-AUTO modes unchanged', function () {
    $executor = new GraphExecutor;

    $options = new ExecutionOptions(streaming: StreamingMode::SSE);

    expect($executor->resolveStreamingMode($options))->toBe(StreamingMode::SSE);
});

it('resolveStreamingMode picks BROADCAST when SSE is disabled', function () {
    config()->set('agenticgraph.streaming.sse.enabled', false);

    $executor = new GraphExecutor;
    $options = new ExecutionOptions(streaming: StreamingMode::AUTO);

    expect($executor->resolveStreamingMode($options))->toBe(StreamingMode::BROADCAST);
});

it('resolveStreamingMode picks SSE when SSE is enabled and duration is below threshold', function () {
    config()->set('agenticgraph.streaming.sse.enabled', true);
    config()->set('agenticgraph.streaming.broadcast.force_above_seconds', 120);

    $executor = new GraphExecutor;
    $options = new ExecutionOptions(
        streaming: StreamingMode::AUTO,
        estimatedDurationSeconds: 30
    );

    expect($executor->resolveStreamingMode($options))->toBe(StreamingMode::SSE);
});

it('resolveStreamingMode picks BROADCAST when estimated duration exceeds threshold', function () {
    config()->set('agenticgraph.streaming.sse.enabled', true);
    config()->set('agenticgraph.streaming.broadcast.force_above_seconds', 60);

    $executor = new GraphExecutor;
    $options = new ExecutionOptions(
        streaming: StreamingMode::AUTO,
        estimatedDurationSeconds: 120
    );

    expect($executor->resolveStreamingMode($options))->toBe(StreamingMode::BROADCAST);
});

it('does not mutate AUTO on the original ExecutionOptions after executeWithOptions', function () {
    registerImmutabilityGraph('immutability-sync-none');

    $original = new ExecutionOptions(
        mode: ExecutionMode::SYNC,
        streaming: StreamingMode::AUTO
    );

    AgenticGraph::execute('immutability-sync-none', new State, $original);

    expect($original->streaming)->toBe(StreamingMode::AUTO);
});

it('dispatches async jobs with resolved streaming mode instead of AUTO', function () {
    Bus::fake();
    config()->set('agenticgraph.streaming.sse.enabled', false);

    registerImmutabilityGraph('immutability-async-auto', streaming: true);

    $original = new ExecutionOptions(
        mode: ExecutionMode::ASYNC,
        streaming: StreamingMode::AUTO
    );

    AgenticGraph::execute('immutability-async-auto', new State, $original);

    expect($original->streaming)->toBe(StreamingMode::AUTO);

    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $job): bool {
        return $job->executionOptions !== null
            && $job->executionOptions->streaming === StreamingMode::BROADCAST;
    });
});

it('dispatches async jobs with SSE when AUTO resolves to SSE', function () {
    Bus::fake();
    config()->set('agenticgraph.streaming.sse.enabled', true);
    config()->set('agenticgraph.streaming.broadcast.force_above_seconds', 0);

    registerImmutabilityGraph('immutability-async-auto-sse', streaming: true);

    $original = new ExecutionOptions(
        mode: ExecutionMode::ASYNC,
        streaming: StreamingMode::AUTO
    );

    AgenticGraph::execute('immutability-async-auto-sse', new State, $original);

    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $job): bool {
        return $job->executionOptions !== null
            && $job->executionOptions->streaming === StreamingMode::SSE;
    });
});

it('ExecuteGraphJob serializes readonly executionOptions with resolved streaming mode', function () {
    $options = new ExecutionOptions(
        mode: ExecutionMode::ASYNC,
        streaming: StreamingMode::BROADCAST,
        interrupts: false,
        estimatedDurationSeconds: 15
    );

    $job = new ExecuteGraphJob(
        graphName: 'immutability-serialize',
        executionId: 'exec-serialize',
        stateData: (new State)->toArray(),
        executionOptions: $options,
        streamId: 'stream-serialize',
        streamSeq: 0
    );

    /** @var ExecuteGraphJob $copy */
    $copy = unserialize(serialize($job));

    expect($copy->executionOptions)->not->toBeNull()
        ->and($copy->executionOptions->streaming)->toBe(StreamingMode::BROADCAST)
        ->and($copy->executionOptions->mode)->toBe(ExecutionMode::ASYNC)
        ->and($copy->executionOptions->interrupts)->toBeFalse()
        ->and($copy->executionOptions->estimatedDurationSeconds)->toBe(15);
});

it('ContinueExecutionJob serializes readonly executionOptions', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');

    registerImmutabilityGraph('immutability-continue-serialize', streaming: true);

    $options = new ExecutionOptions(
        mode: ExecutionMode::ASYNC,
        streaming: StreamingMode::ITERATOR
    );

    $job = new ContinueExecutionJob(
        'immutability-continue-serialize',
        'exec-continue-serialize',
        (new State)->toArray(),
        'start',
        null,
        $options,
        'stream-continue-serialize',
        0
    );

    /** @var ContinueExecutionJob $copy */
    $copy = unserialize(serialize($job));

    expect($copy->executionOptions)->not->toBeNull()
        ->and($copy->executionOptions->streaming)->toBe(StreamingMode::ITERATOR);

    $copy->handle(new GraphExecutor, app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class));

    $store = app(\Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface::class);
    $events = iterator_to_array($store->readFrom('stream-continue-serialize', 1, 5));

    expect($events)->not->toBeEmpty()
        ->and($events[0]->streamId())->toBe('stream-continue-serialize');
});

it('ExecuteGraphJob resolves AUTO to broadcast and emits through the broadcast sink', function () {
    config()->set('agenticgraph.streaming.sse.enabled', false);

    registerImmutabilityGraph('immutability-job-auto-broadcast', streaming: true);

    $streamId = uniqid('stream_', true);
    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->atLeast()->once()->with(
        Mockery::on(fn (array $channels): bool => isset($channels[0]) && $channels[0] === 'agenticgraph.stream.'.$streamId),
        Mockery::type('string'),
        Mockery::type('array')
    );
    app()->instance(Broadcaster::class, $broadcaster);

    $job = new ExecuteGraphJob(
        graphName: 'immutability-job-auto-broadcast',
        executionId: uniqid('exec_', true),
        stateData: (new State)->toArray(),
        checkpointId: null,
        rootSpanId: uniqid('span_', true),
        executionOptions: new ExecutionOptions(
            mode: ExecutionMode::ASYNC,
            streaming: StreamingMode::AUTO
        ),
        streamId: $streamId,
        streamSeq: 0
    );

    $job->handle(
        app(GraphExecutor::class),
        app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)
    );
});
