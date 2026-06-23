<?php

use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeInterrupted;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

class HonestInterruptNode extends PrismNode
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

class HonestInterruptStreamNode extends PrismNode
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

class HonestCompleteNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('done', true);
    }
}

function registerHonestInterruptGraph(string $name, string $nodeClass): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('start', $nodeClass)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register($name, $graph);
}

beforeEach(function () {
    config()->set('agenticgraph.checkpoint.enabled', true);
});

it('reports SYNC+NONE interrupt honestly in DB, events, and handle', function () {
    Event::fake([GraphExecutionCompleted::class, NodeInterrupted::class]);
    registerHonestInterruptGraph('honest-sync-none-interrupt', HonestInterruptNode::class);

    $handle = AgenticGraph::execute(
        'honest-sync-none-interrupt',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE)
    );

    $record = app(ExecutionStore::class)->get($handle->id());
    $finalState = $handle->getFinalState();

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ExecutionStatus::INTERRUPTED)
        ->and($handle->status())->toBe(ExecutionStatus::INTERRUPTED)
        ->and($handle->isInterrupted())->toBeTrue()
        ->and($finalState->hasInterrupt())->toBeTrue();

    Event::assertNotDispatched(GraphExecutionCompleted::class);
    Event::assertDispatched(NodeInterrupted::class);
});

it('reports SYNC+ITERATOR interrupt honestly on the handle', function () {
    Event::fake([NodeInterrupted::class]);
    registerHonestInterruptGraph('honest-sync-iterator-interrupt', HonestInterruptStreamNode::class);

    $handle = AgenticGraph::execute(
        'honest-sync-iterator-interrupt',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR)
    );

    $record = app(ExecutionStore::class)->get($handle->id());

    expect($record?->status)->toBe(ExecutionStatus::INTERRUPTED)
        ->and($handle->status())->toBe(ExecutionStatus::INTERRUPTED)
        ->and($handle->isInterrupted())->toBeTrue()
        ->and($handle->getFinalState()->hasInterrupt())->toBeTrue();

    Event::assertDispatched(NodeInterrupted::class);
});

it('still completes non-interrupt SYNC+NONE runs honestly', function () {
    Event::fake([GraphExecutionCompleted::class]);
    registerHonestInterruptGraph('honest-sync-none-complete', HonestCompleteNode::class);

    $handle = AgenticGraph::execute(
        'honest-sync-none-complete',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE)
    );

    $record = app(ExecutionStore::class)->get($handle->id());

    expect($record?->status)->toBe(ExecutionStatus::COMPLETED)
        ->and($handle->status())->toBe(ExecutionStatus::COMPLETED)
        ->and($handle->isInterrupted())->toBeFalse()
        ->and($handle->getFinalState()->get('done'))->toBeTrue();

    Event::assertDispatched(GraphExecutionCompleted::class);
});

it('preserves execution id and sync mode on interrupted handles', function () {
    registerHonestInterruptGraph('honest-mode-preserved', HonestInterruptNode::class);

    $handle = AgenticGraph::execute(
        'honest-mode-preserved',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR)
    );

    expect($handle->status())->toBe(ExecutionStatus::INTERRUPTED)
        ->and($handle->isSync())->toBeTrue()
        ->and($handle->isAsync())->toBeFalse()
        ->and($handle->id())->not->toBeEmpty();
});
