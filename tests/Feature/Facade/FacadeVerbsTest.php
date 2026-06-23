<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamDescriptor;
use Shahzad\AgenticGraph\Streaming\StreamLifecycleEventTypes;

class VerbsSimpleNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('done', true);
    }
}

class VerbsAsyncStreamNode extends AbstractNode
{
    protected ?string $name = 'async';

    public function execute(StateContract $state): StateContract
    {
        if ($this->isStreaming()) {
            $this->streamEvent('text_delta', ['delta' => 'async']);

            return $state->set('streamed', true);
        }

        return $state;
    }
}

class VerbsSyncIteratorNode extends AbstractNode
{
    protected ?string $name = 'sync';

    public function execute(StateContract $state): StateContract
    {
        if ($this->isStreaming()) {
            $this->streamEvent('text_delta', ['delta' => 'iterator']);

            return $state->set('path', 'streaming');
        }

        return $state->set('path', 'sync');
    }
}

function registerVerbsSimpleGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('start', VerbsSimpleNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register($name, $graph);
}

function registerVerbsAsyncStreamGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('stream', VerbsAsyncStreamNode::class)
        ->setEntryPoint('stream')
        ->addExitPoint('stream')
        ->build();

    AgenticGraph::register($name, $graph);
}

function registerVerbsSyncIteratorGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('alpha', VerbsSyncIteratorNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register($name, $graph);
}

function persistedVerbExecutionOptions(string $executionId): ExecutionOptions
{
    $json = DB::table('agenticgraph_executions')->where('id', $executionId)->value('execution_options');

    return ExecutionOptions::fromArray(json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR));
}

function runVerbAsyncJob(): ExecuteGraphJob
{
    $job = null;

    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $dispatched) use (&$job): bool {
        $job = $dispatched;

        return true;
    });

    expect($job)->toBeInstanceOf(ExecuteGraphJob::class);

    $job->handle(
        app(GraphExecutor::class),
        app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)
    );

    return $job;
}

beforeEach(function () {
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    config()->set('agenticgraph.streaming.routes.enabled', true);
    config()->set('agenticgraph.streaming.routes.prefix', 'agentic-graph/streams');
    config()->set('agenticgraph.streaming.store.driver', 'database');
});

it('run is equivalent to execute with SYNC and NONE', function () {
    registerVerbsSimpleGraph('verbs-run-equiv');

    $verbHandle = AgenticGraph::run('verbs-run-equiv', new State);
    $explicitHandle = AgenticGraph::execute(
        'verbs-run-equiv',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE)
    );

    expect($verbHandle->status())->toBe(ExecutionStatus::COMPLETED)
        ->and($explicitHandle->status())->toBe(ExecutionStatus::COMPLETED)
        ->and($verbHandle->getFinalState()->get('done'))->toBeTrue()
        ->and($verbHandle->stream()->mode)->toBe(StreamingMode::NONE)
        ->and($verbHandle->stream()->hasStream())->toBeFalse()
        ->and($verbHandle->streamId())->toBeNull();
});

it('dispatch is equivalent to execute with ASYNC and NONE', function () {
    Bus::fake();
    registerVerbsSimpleGraph('verbs-dispatch-equiv');

    $verbHandle = AgenticGraph::dispatch('verbs-dispatch-equiv', new State);
    $explicitHandle = AgenticGraph::execute(
        'verbs-dispatch-equiv',
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::NONE)
    );

    expect($verbHandle->status())->toBe(ExecutionStatus::QUEUED)
        ->and($explicitHandle->status())->toBe(ExecutionStatus::QUEUED)
        ->and($verbHandle->isAsync())->toBeTrue()
        ->and($verbHandle->stream()->mode)->toBe(StreamingMode::NONE);

    Bus::assertDispatched(ExecuteGraphJob::class, 2);
});

it('stream default is equivalent to execute with ASYNC and SSE', function () {
    Bus::fake();
    registerVerbsAsyncStreamGraph('verbs-stream-async');

    $verbHandle = AgenticGraph::stream('verbs-stream-async', new State);
    $explicitHandle = AgenticGraph::execute(
        'verbs-stream-async',
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::SSE)
    );

    expect($verbHandle->status())->toBe(ExecutionStatus::QUEUED)
        ->and($explicitHandle->status())->toBe(ExecutionStatus::QUEUED);

    runVerbAsyncJob();

    $verbDescriptor = $verbHandle->stream();
    $explicitDescriptor = $explicitHandle->stream();

    expect($verbDescriptor->mode)->toBe(StreamingMode::SSE)
        ->and($explicitDescriptor->mode)->toBe(StreamingMode::SSE)
        ->and($verbDescriptor->hasUrl())->toBeTrue()
        ->and($verbDescriptor->streamUrl)->toBe(route(StreamDescriptor::ROUTE_NAME, ['streamId' => $verbDescriptor->streamId]))
        ->and($verbDescriptor->inline)->toBeNull();
});

it('stream with inline true is equivalent to execute with SYNC and ITERATOR', function () {
    registerVerbsSyncIteratorGraph('verbs-stream-inline');

    $verbHandle = AgenticGraph::stream('verbs-stream-inline', new State, inline: true);
    $explicitHandle = AgenticGraph::execute(
        'verbs-stream-inline',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR)
    );

    $verbDescriptor = $verbHandle->stream();
    $events = iterator_to_array($verbDescriptor->inline ?? new ArrayIterator([]));

    expect($verbHandle->status())->toBe(ExecutionStatus::COMPLETED)
        ->and($explicitHandle->status())->toBe(ExecutionStatus::COMPLETED)
        ->and($verbDescriptor->isInline())->toBeTrue()
        ->and($verbDescriptor->mode)->toBe(StreamingMode::ITERATOR)
        ->and($verbDescriptor->hasUrl())->toBeFalse()
        ->and(array_map(fn ($event) => $event->type(), $events))->toContain('text_delta')
        ->and($verbHandle->getFinalState()->get('path'))->toBe('streaming');
});

it('broadcast default is equivalent to execute with ASYNC and BROADCAST', function () {
    Bus::fake();
    registerVerbsAsyncStreamGraph('verbs-broadcast-async');

    $verbHandle = AgenticGraph::broadcast('verbs-broadcast-async', new State);
    $explicitHandle = AgenticGraph::execute(
        'verbs-broadcast-async',
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::BROADCAST)
    );

    expect($verbHandle->status())->toBe(ExecutionStatus::QUEUED)
        ->and($explicitHandle->status())->toBe(ExecutionStatus::QUEUED)
        ->and($verbHandle->isAsync())->toBeTrue();

    runVerbAsyncJob();

    $verbDescriptor = $verbHandle->stream();

    expect($verbDescriptor->mode)->toBe(StreamingMode::BROADCAST)
        ->and($verbDescriptor->hasUrl())->toBeTrue();
});

it('broadcast with async false is equivalent to execute with SYNC and BROADCAST', function () {
    registerVerbsAsyncStreamGraph('verbs-broadcast-sync');

    $verbHandle = AgenticGraph::broadcast('verbs-broadcast-sync', new State, async: false);
    $explicitHandle = AgenticGraph::execute(
        'verbs-broadcast-sync',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::BROADCAST)
    );

    expect($verbHandle->status())->toBe(ExecutionStatus::COMPLETED)
        ->and($explicitHandle->status())->toBe(ExecutionStatus::COMPLETED)
        ->and($verbHandle->isSync())->toBeTrue()
        ->and($verbHandle->stream()->mode)->toBe(StreamingMode::BROADCAST);
});

it('stream threads nodes and events filters onto persisted execution options', function () {
    Bus::fake();
    registerVerbsAsyncStreamGraph('verbs-stream-filters');

    $handle = AgenticGraph::stream(
        'verbs-stream-filters',
        new State,
        nodes: ['alpha', 'gamma'],
        events: ['text_delta'],
    );

    $queued = persistedVerbExecutionOptions($handle->id());

    expect($queued)->toEqual(new ExecutionOptions(
        mode: ExecutionMode::ASYNC,
        streaming: StreamingMode::SSE,
        nodeFilter: ['alpha', 'gamma'],
        eventTypeFilter: ['text_delta'],
    ));

    runVerbAsyncJob();

    expect(persistedVerbExecutionOptions($handle->id()))->toEqual($queued);
});

it('broadcast threads nodes and events filters onto persisted execution options', function () {
    Bus::fake();
    registerVerbsAsyncStreamGraph('verbs-broadcast-filters');

    $handle = AgenticGraph::broadcast(
        'verbs-broadcast-filters',
        new State,
        nodes: ['alpha'],
        events: [StreamLifecycleEventTypes::NODE_COMPLETE],
    );

    expect(persistedVerbExecutionOptions($handle->id()))->toEqual(new ExecutionOptions(
        mode: ExecutionMode::ASYNC,
        streaming: StreamingMode::BROADCAST,
        nodeFilter: ['alpha'],
        eventTypeFilter: [StreamLifecycleEventTypes::NODE_COMPLETE],
    ));
});

it('execute with explicit ExecutionOptions remains the power API', function () {
    registerVerbsSimpleGraph('verbs-power-api');

    $custom = new ExecutionOptions(
        mode: ExecutionMode::ASYNC,
        streaming: StreamingMode::NONE,
        interrupts: false,
        estimatedDurationSeconds: 99,
        nodeFilter: ['custom'],
    );

    Bus::fake();
    $handle = AgenticGraph::execute('verbs-power-api', new State, $custom);

    expect($handle->status())->toBe(ExecutionStatus::QUEUED)
        ->and(persistedVerbExecutionOptions($handle->id()))->toEqual($custom);
});
