<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\LoopLimitExceededException;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Routers\AbstractRouter;
use Shahzad\AgenticGraph\State\State;

class LoopLimitCycleNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('visits', ($state->get('visits', 0)) + 1);
    }
}

class LoopLimitCycleRouter extends AbstractRouter
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

it('dispatches GraphExecutionFailed exactly once when loop guard trips', function () {
    Event::fake([GraphExecutionFailed::class]);
    config()->set('agenticgraph.execution.loop.enabled', true);
    config()->set('agenticgraph.execution.loop.max_iterations', 3);
    config()->set('agenticgraph.execution.loop.max_cycle_iterations', null);
    config()->set('agenticgraph.execution.loop.timeout_seconds', null);

    $graph = GraphBuilder::create('loop-limit-failed-once')
        ->addNode('A', LoopLimitCycleNode::class)
        ->addNode('B', LoopLimitCycleNode::class)
        ->addNode('end', LoopLimitCycleNode::class)
        ->addConditionalEdge('A', LoopLimitCycleRouter::class)
        ->addEdge('B', 'A')
        ->setEntryPoint('A')
        ->addExitPoint('end')
        ->build();

    app(GraphRegistry::class)->register('loop-limit-failed-once', $graph);

    $executor = new GraphExecutor;
    $executionId = 'exec_loop_limit_'.Str::uuid();

    try {
        $executor->execute(
            app(GraphRegistry::class)->get('loop-limit-failed-once'),
            new State,
            executionId: $executionId
        );
        expect(false)->toBeTrue('Expected loop-limit execution to throw');
    } catch (LoopLimitExceededException $exception) {
        expect($exception->getMessage())->toContain('exceeded maximum iterations');
    }

    $record = app(ExecutionStore::class)->get($executionId);

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ExecutionStatus::FAILED)
        ->and($record->error)->toContain('exceeded maximum iterations');

    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);

    Event::assertDispatched(GraphExecutionFailed::class, function (GraphExecutionFailed $event) use ($executionId) {
        return $event->executionId === $executionId
            && $event->graphName === 'loop-limit-failed-once'
            && $event->exception instanceof LoopLimitExceededException
            && str_contains($event->exception->getMessage(), 'exceeded maximum iterations');
    });
});
