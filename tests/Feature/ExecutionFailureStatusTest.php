<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\NodeInterrupted;
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

class FailureThrowingNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        throw new RuntimeException('sync node failed');
    }
}

class FailureThrowingStreamNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        if ($this->isStreaming()) {
            throw new RuntimeException('streaming node failed');
        }

        return $state;
    }
}

class FailureInterruptNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('visited', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->has('approved');
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Needs approval';
    }
}

afterEach(function () {
    Mockery::close();
    app()->forgetInstance(CheckpointManager::class);
});

function registerFailureGraph(string $name, string $nodeClass): GraphExecutor
{
    $graph = GraphBuilder::create($name)
        ->addNode('start', $nodeClass)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register($name, $graph);

    return new GraphExecutor;
}

it('marks SYNC execution failed when a node throws', function () {
    Event::fake([GraphExecutionFailed::class]);
    config()->set('agenticgraph.streaming.store.driver', 'database');

    $executor = registerFailureGraph('sync-failure', FailureThrowingNode::class);
    $executionId = 'exec_sync_fail_'.Str::uuid();

    try {
        $executor->execute(
            app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->get('sync-failure'),
            new State,
            executionId: $executionId
        );
        expect(false)->toBeTrue('Expected execution to throw');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('sync node failed');
    }

    $record = app(ExecutionStore::class)->get($executionId);

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ExecutionStatus::FAILED);

    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
});

it('marks SYNC+ITERATOR execution failed when a streaming node throws', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');

    $graph = GraphBuilder::create('stream-failure-status')
        ->addNode('start', FailureThrowingStreamNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register('stream-failure-status', $graph);

    try {
        AgenticGraph::execute(
            'stream-failure-status',
            new State,
            new ExecutionOptions(
                mode: ExecutionMode::SYNC,
                streaming: StreamingMode::ITERATOR
            )
        );
        expect(false)->toBeTrue('Expected streaming execution to throw');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('streaming node failed');
    }

    $executionId = \Illuminate\Support\Facades\DB::table('agenticgraph_executions')
        ->where('graph_name', 'stream-failure-status')
        ->orderByDesc('created_at')
        ->value('id');

    $record = app(ExecutionStore::class)->get($executionId);

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ExecutionStatus::FAILED);
});

it('does not mark a clean SYNC interrupt as failed', function () {
    Event::fake([GraphExecutionFailed::class, NodeInterrupted::class]);
    config()->set('agenticgraph.checkpoint.enabled', true);

    $executor = registerFailureGraph('sync-interrupt-clean', FailureInterruptNode::class);
    $executionId = 'exec_sync_interrupt_'.Str::uuid();

    $executor->execute(
        app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->get('sync-interrupt-clean'),
        new State,
        executionId: $executionId
    );

    $record = app(ExecutionStore::class)->get($executionId);

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ExecutionStatus::INTERRUPTED);

    Event::assertDispatched(NodeInterrupted::class);
    Event::assertNotDispatched(GraphExecutionFailed::class);
});

it('marks execution failed when interrupt handling throws', function () {
    Event::fake([GraphExecutionFailed::class]);

    $checkpoints = Mockery::mock(CheckpointManager::class)->makePartial();
    $checkpoints->shouldReceive('saveInterrupt')
        ->once()
        ->andThrow(new RuntimeException('interrupt checkpoint failed'));

    app()->forgetInstance(CheckpointManager::class);
    app()->instance(CheckpointManager::class, $checkpoints);

    config()->set('agenticgraph.checkpoint.enabled', true);

    $executor = registerFailureGraph('sync-interrupt-failure', FailureInterruptNode::class);
    $executionId = 'exec_sync_interrupt_fail_'.Str::uuid();

    try {
        $executor->execute(
            app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->get('sync-interrupt-failure'),
            new State,
            executionId: $executionId
        );
        expect(false)->toBeTrue('Expected interrupt handling to throw');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('interrupt checkpoint failed');
    }

    $record = app(ExecutionStore::class)->get($executionId);

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ExecutionStatus::FAILED);

    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
});

it('treats failed() as idempotent on terminal rows', function () {
    $repository = new ExecutionRepository;
    $executionId = (string) Str::uuid();
    $payload = State::toStoragePayload(new State);

    $repository->queued($executionId, 'idempotent-failed', $payload);
    $repository->started($executionId, 'idempotent-failed', $payload);
    $repository->failed($executionId, 'first failure');

    $first = $repository->get($executionId);
    expect($first?->status)->toBe(ExecutionStatus::FAILED)
        ->and($first?->error)->toBe('first failure');

    $repository->failed($executionId, 'second failure');

    $second = $repository->get($executionId);
    expect($second?->status)->toBe(ExecutionStatus::FAILED)
        ->and($second?->error)->toBe('first failure');
});

it('allows interrupted executions to transition to failed', function () {
    $repository = new ExecutionRepository;
    $executionId = (string) Str::uuid();
    $payload = State::toStoragePayload(new State);

    $repository->queued($executionId, 'interrupted-to-failed', $payload);
    $repository->started($executionId, 'interrupted-to-failed', $payload);
    $repository->interrupted($executionId);
    $repository->failed($executionId, 'interrupt handling failed');

    $record = $repository->get($executionId);

    expect($record?->status)->toBe(ExecutionStatus::FAILED)
        ->and($record?->error)->toBe('interrupt handling failed');
});

it('does not overwrite completed executions when failed() is called again', function () {
    $repository = new ExecutionRepository;
    $executionId = (string) Str::uuid();
    $payload = State::toStoragePayload(new State(['done' => true]));

    $repository->queued($executionId, 'completed-no-op', $payload);
    $repository->started($executionId, 'completed-no-op', $payload);
    $repository->completed($executionId, $payload, 1.0);
    $repository->failed($executionId, 'late failure');

    $record = $repository->get($executionId);

    expect($record?->status)->toBe(ExecutionStatus::COMPLETED)
        ->and($record?->error)->toBeNull();
});

it('ignores a second failed() after an inner catch already marked the execution failed', function () {
    $repository = new ExecutionRepository;
    $executionId = (string) Str::uuid();
    $payload = State::toStoragePayload(new State);

    $repository->queued($executionId, 'nested-failed', $payload);
    $repository->started($executionId, 'nested-failed', $payload);

    $repository->failed($executionId, 'Loop limit exceeded');
    $repository->failed($executionId, 'Loop limit exceeded');

    $record = $repository->get($executionId);

    expect($record?->status)->toBe(ExecutionStatus::FAILED)
        ->and($record?->error)->toBe('Loop limit exceeded');
});
