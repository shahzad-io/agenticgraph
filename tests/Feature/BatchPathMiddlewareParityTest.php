<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionTimed;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\NodeMiddlewarePipeline;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Middleware\TimingMiddleware;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

class BatchMarkerMiddleware
{
    public function handle(StateContract $state, NodeInterface $node, Closure $next): StateContract
    {
        return $next($state->set('batch_middleware_ran', true));
    }
}

class BatchStartNode extends AbstractNode
{
    protected ?string $name = 'start';

    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class BatchBranchOneNode extends AbstractNode
{
    protected ?string $name = 'branch_one';

    public function execute(StateContract $state): StateContract
    {
        return $state->set('branch_one_ran', true);
    }
}

class BatchBranchTwoNode extends AbstractNode
{
    protected ?string $name = 'branch_two';

    public function execute(StateContract $state): StateContract
    {
        return $state->set('branch_two_ran', true);
    }
}

class BatchJoinNode extends AbstractNode
{
    protected ?string $name = 'join';

    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class BatchEndNode extends AbstractNode
{
    protected ?string $name = 'end';

    public function execute(StateContract $state): StateContract
    {
        return $state->set('end_ran', true);
    }
}

class BatchForkRouter implements RouterInterface
{
    public function route(StateContract $state): string|array
    {
        return ['branch_one', 'branch_two'];
    }

    public function getAvailableRoutes(): array
    {
        return ['branch_one', 'branch_two'];
    }

    public function getDefaultRoute(): ?string
    {
        return null;
    }

    public function validateRoute(string $route): bool
    {
        return in_array($route, ['branch_one', 'branch_two'], true);
    }
}

class BatchJoinRouter implements RouterInterface
{
    public function route(StateContract $state): string|array
    {
        return 'end';
    }

    public function getAvailableRoutes(): array
    {
        return ['end'];
    }

    public function getDefaultRoute(): ?string
    {
        return 'end';
    }

    public function validateRoute(string $route): bool
    {
        return $route === 'end';
    }
}

function buildBatchMiddlewareParityGraph(string $name): Graph
{
    return GraphBuilder::create($name)
        ->addNode('start', BatchStartNode::class)
        ->addNode('branch_one', BatchBranchOneNode::class)
        ->addNode('branch_two', BatchBranchTwoNode::class)
        ->addNode('join', BatchJoinNode::class)
        ->addNode('end', BatchEndNode::class)
        ->addConditionalEdge('start', new BatchForkRouter)
        ->addJoin(['branch_one', 'branch_two'], 'join')
        ->addEdge('branch_one', 'join')
        ->addEdge('branch_two', 'join')
        ->addConditionalEdge('join', new BatchJoinRouter)
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
}

it('runs global middleware inside ExecuteNodeJob', function (): void {
    config()->set('agenticgraph.middleware.global', [
        BatchMarkerMiddleware::class,
        TimingMiddleware::class,
    ]);

    $graph = GraphBuilder::create('batch-job-middleware')
        ->addNode('branch_one', BatchBranchOneNode::class)
        ->setEntryPoint('branch_one')
        ->addExitPoint('branch_one')
        ->build();

    app(GraphRegistry::class)->register('batch-job-middleware', $graph);

    Event::fake([NodeExecutionTimed::class]);

    $job = new ExecuteNodeJob(
        executionId: 'exec-batch-job-mw',
        nodeName: 'branch_one',
        stateData: (new State)->toArray(),
        graphName: 'batch-job-middleware',
    );

    $job->handle(app(GraphRegistry::class), app(NodeMiddlewarePipeline::class));

    $cached = Cache::get('agenticgraph:batch:exec-batch-job-mw:branch_one');
    expect($cached)->toBeArray()
        ->and($cached['batch_middleware_ran'] ?? null)->toBeTrue()
        ->and($cached['branch_one_ran'] ?? null)->toBeTrue();

    Event::assertDispatched(NodeExecutionTimed::class);
});

it('leaves sync in-process parallel middleware behavior unchanged', function (): void {
    config()->set('agenticgraph.middleware.global', [
        BatchMarkerMiddleware::class,
        TimingMiddleware::class,
    ]);

    $graph = buildBatchMiddlewareParityGraph('sync-middleware-parity');
    app(GraphRegistry::class)->register('sync-middleware-parity', $graph);

    Event::fake([
        GraphExecutionStarted::class,
        GraphExecutionCompleted::class,
        NodeExecutionTimed::class,
    ]);

    $finalState = app(GraphExecutor::class)->execute(
        $graph,
        new State,
        new ExecutionOptions(ExecutionMode::SYNC, StreamingMode::NONE),
        useBatch: false
    );

    expect($finalState->get('batch_middleware_ran'))->toBeTrue()
        ->and($finalState->get('branch_one_ran'))->toBeTrue()
        ->and($finalState->get('branch_two_ran'))->toBeTrue()
        ->and($finalState->get('end_ran'))->toBeTrue();

    Event::assertDispatched(GraphExecutionStarted::class);
    Event::assertDispatched(GraphExecutionCompleted::class);
    Event::assertDispatched(NodeExecutionTimed::class);
});
