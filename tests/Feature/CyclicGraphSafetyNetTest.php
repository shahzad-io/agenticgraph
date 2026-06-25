<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\LoopLimitExceededException;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Routers\AbstractRouter;
use Shahzad\AgenticGraph\State\State;

class CyclicSafetyNetNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('visits', ($state->get('visits', 0)) + 1);
    }
}

class CyclicSafetyNetRouter extends AbstractRouter
{
    public function route(StateContract $state): string|array
    {
        return 'B';
    }

    public function getAvailableRoutes(): array
    {
        return ['B', 'end'];
    }
}

class LinearSafetyNetNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('step', ($state->get('step', 0)) + 1);
    }
}

function buildCyclicSafetyNetGraph(string $name): Graph
{
    return GraphBuilder::create($name)
        ->addNode('A', CyclicSafetyNetNode::class)
        ->addNode('B', CyclicSafetyNetNode::class)
        ->addNode('end', CyclicSafetyNetNode::class)
        ->addConditionalEdge('A', CyclicSafetyNetRouter::class)
        ->addEdge('B', 'A')
        ->setEntryPoint('A')
        ->addExitPoint('end')
        ->build();
}

function buildLinearSafetyNetGraph(string $name, int $nodeCount = 12): Graph
{
    $builder = GraphBuilder::create($name)
        ->addNode('n0', LinearSafetyNetNode::class);

    for ($i = 1; $i < $nodeCount; $i++) {
        $builder->addNode("n{$i}", LinearSafetyNetNode::class)
            ->addEdge('n'.($i - 1), "n{$i}");
    }

    return $builder
        ->setEntryPoint('n0')
        ->addExitPoint('n'.($nodeCount - 1))
        ->build();
}

it('caps cyclic graphs with loop guard disabled via the default safety net', function () {
    Event::fake([GraphExecutionFailed::class]);
    config()->set('agenticgraph.execution.loop.enabled', false);
    config()->set('agenticgraph.execution.max_iterations', 5);

    $graphName = 'cyclic-safety-net-sync-'.Str::uuid();
    $graph = buildCyclicSafetyNetGraph($graphName);

    app(GraphRegistry::class)->register($graphName, $graph);

    $executor = new GraphExecutor;
    $executionId = 'exec_cyclic_safety_'.Str::uuid();

    try {
        $executor->execute(
            app(GraphRegistry::class)->get($graphName),
            new State,
            executionId: $executionId
        );
        expect(false)->toBeTrue('Expected cyclic safety net to throw');
    } catch (LoopLimitExceededException $exception) {
        expect($exception->getMessage())->toContain('exceeded maximum iterations')
            ->and($exception->getMessage())->toContain('cyclic graph');
    }

    $record = app(ExecutionStore::class)->get($executionId);

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ExecutionStatus::FAILED);
});

it('does not cap acyclic linear graphs when loop guard is disabled', function () {
    config()->set('agenticgraph.execution.loop.enabled', false);
    config()->set('agenticgraph.execution.max_iterations', 1000);

    $graphName = 'linear-safety-net-'.Str::uuid();
    $graph = buildLinearSafetyNetGraph($graphName, 12);

    app(GraphRegistry::class)->register($graphName, $graph);

    $executor = new GraphExecutor;
    $finalState = $executor->execute(
        app(GraphRegistry::class)->get($graphName),
        new State,
        executionId: 'exec_linear_safety_'.Str::uuid()
    );

    expect($finalState->get('step'))->toBe(12);
});

it('stores has_cycles metadata on built graphs', function () {
    $acyclic = buildLinearSafetyNetGraph('metadata-acyclic-'.Str::uuid(), 3);
    $cyclic = buildCyclicSafetyNetGraph('metadata-cyclic-'.Str::uuid());

    expect($acyclic->hasCycles())->toBeFalse()
        ->and($acyclic->metadata['has_cycles'] ?? null)->toBeFalse()
        ->and($cyclic->hasCycles())->toBeTrue()
        ->and($cyclic->metadata['has_cycles'] ?? null)->toBeTrue();
});

it('caps cyclic graphs on the streaming execute path when loop guard is disabled', function () {
    config()->set('agenticgraph.execution.loop.enabled', false);
    config()->set('agenticgraph.execution.max_iterations', 5);

    $graphName = 'cyclic-safety-net-stream-'.Str::uuid();
    $graph = buildCyclicSafetyNetGraph($graphName);
    AgenticGraph::register($graphName, $graph);

    expect(fn () => AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR)
    ))->toThrow(LoopLimitExceededException::class, 'exceeded maximum iterations');
});

it('still uses the full loop guard when loop guard is enabled', function () {
    config()->set('agenticgraph.execution.loop.enabled', true);
    config()->set('agenticgraph.execution.loop.max_iterations', 3);
    config()->set('agenticgraph.execution.max_iterations', 1000);

    $graphName = 'cyclic-full-guard-'.Str::uuid();
    $graph = buildCyclicSafetyNetGraph($graphName);
    app(GraphRegistry::class)->register($graphName, $graph);

    $executor = new GraphExecutor;

    try {
        $executor->execute(
            app(GraphRegistry::class)->get($graphName),
            new State,
            executionId: 'exec_full_guard_'.Str::uuid()
        );
        expect(false)->toBeTrue('Expected full loop guard to throw');
    } catch (LoopLimitExceededException $exception) {
        expect($exception->getMessage())->toContain('exceeded maximum iterations');
    }
});
