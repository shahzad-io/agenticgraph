<?php

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\NodeMiddlewarePipeline;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

/**
 * Branch results of an async fan-out are stored in agenticgraph_batch_results until the batch ends. They were cached
 * for 300 seconds: a batch that took longer after its first branch finished lost that result, and the execution
 * stayed running.
 */
class PBDBranchA extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('a_done', true);
    }
}

class PBDBranchB extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('b_done', true);
    }
}

class PBDPassNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class PBDFork implements RouterInterface
{
    public function route(StateContract $state): string|array
    {
        return ['a', 'b'];
    }

    public function getAvailableRoutes(): array
    {
        return ['a', 'b'];
    }

    public function getDefaultRoute(): ?string
    {
        return null;
    }

    public function validateRoute(string $route): bool
    {
        return in_array($route, ['a', 'b'], true);
    }
}

function pbdRunBranch(string $executionId, string $node): void
{
    (new ExecuteNodeJob($executionId, $node, (new State(['topic' => 'demo']))->toArray(), 'pbd'))
        ->handle(app(GraphRegistry::class), app(NodeMiddlewarePipeline::class));
}

function pbdComplete(string $executionId): void
{
    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-pbd';
    app(ParallelExecutionCoordinator::class)->handleBatchCompletion($batch, 'pbd', $executionId, 'start', ['a', 'b']);
}

beforeEach(function () {
    Bus::fake();
    config()->set('agenticgraph.checkpoint.enabled', false);
    app(GraphRegistry::class)->register('pbd', GraphBuilder::create('pbd')
        ->addNode('start', PBDPassNode::class)
        ->addNode('a', PBDBranchA::class)
        ->addNode('b', PBDBranchB::class)
        ->addNode('end', PBDPassNode::class)
        ->addConditionalEdge('start', PBDFork::class)
        ->addEdge('a', 'end')
        ->addEdge('b', 'end')
        ->addJoin(['a', 'b'], 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build());
});

it('continues at the join when a sibling branch finishes more than 300 seconds later', function () {
    app(ExecutionRepository::class)->queued('exec-pbd-slow', 'pbd');
    pbdRunBranch('exec-pbd-slow', 'a');
    $this->travel(301)->seconds();
    pbdRunBranch('exec-pbd-slow', 'b');

    pbdComplete('exec-pbd-slow');

    Bus::assertDispatched(ContinueExecutionJob::class, fn (ContinueExecutionJob $job): bool => $job->startNode === 'end'
        && ($job->stateData['a_done'] ?? false) === true
        && ($job->stateData['b_done'] ?? false) === true
        && ($job->stateData['topic'] ?? null) === 'demo');
});

it('stores a branch result as a row, not in the cache, and deletes the rows when the batch ends', function () {
    app(ExecutionRepository::class)->queued('exec-pbd-rows', 'pbd');
    pbdRunBranch('exec-pbd-rows', 'a');
    pbdRunBranch('exec-pbd-rows', 'b');

    expect(DB::table('agenticgraph_batch_results')->where('execution_id', 'exec-pbd-rows')->orderBy('node_name')->pluck('node_name')->all())->toBe(['a', 'b'])
        ->and(Cache::get('agenticgraph:batch:exec-pbd-rows:a'))->toBeNull();

    app(ParallelExecutionCoordinator::class)->cleanupBatchResults('exec-pbd-rows', ['a', 'b']);

    expect(DB::table('agenticgraph_batch_results')->where('execution_id', 'exec-pbd-rows')->count())->toBe(0);
});

it('keeps the newest result when the same branch runs again in one execution', function () {
    $repo = app(ExecutionRepository::class);
    $repo->putBatchResult('exec-pbd-again', 'a', ['round' => 1]);
    $repo->putBatchResult('exec-pbd-again', 'a', ['round' => 2]);

    expect($repo->batchResults('exec-pbd-again'))->toBe(['a' => ['round' => 2]]);
});

it('reads a result an older job left under the cache key', function () {
    Cache::put('agenticgraph:batch:exec-pbd-old:a', (new State(['a_done' => true]))->toArray(), 300);
    app(ExecutionRepository::class)->queued('exec-pbd-old', 'pbd');
    pbdRunBranch('exec-pbd-old', 'b');

    pbdComplete('exec-pbd-old');

    Bus::assertDispatched(ContinueExecutionJob::class, fn (ContinueExecutionJob $job): bool => ($job->stateData['a_done'] ?? false) === true && ($job->stateData['b_done'] ?? false) === true);
});

it('fails the execution when a branch result is missing', function () {
    Event::fake([GraphExecutionFailed::class]);
    $repo = app(ExecutionRepository::class);
    $repo->queued('exec-pbd-missing', 'pbd');
    $repo->started('exec-pbd-missing', 'pbd');
    pbdRunBranch('exec-pbd-missing', 'b');

    $started = microtime(true);
    pbdComplete('exec-pbd-missing');

    expect($repo->getStatus('exec-pbd-missing'))->toBe('failed')
        ->and($repo->get('exec-pbd-missing')?->error)->toContain('Missing batch results for nodes: a')
        ->and(microtime(true) - $started)->toBeLessThan(1.0); // no retry sleeps
    Event::assertDispatched(GraphExecutionFailed::class, fn (GraphExecutionFailed $event): bool => $event->executionId === 'exec-pbd-missing');
    Bus::assertNotDispatched(ContinueExecutionJob::class);
});
