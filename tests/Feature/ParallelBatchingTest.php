<?php

use Illuminate\Bus\Batch;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\RoutingEngine;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\State\StateManager;

class PBStart extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class PBNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

afterEach(function () {
    Mockery::close();
    Cache::flush();
});

it('uses Bus::batch for parallel execution', function () {
    Bus::fake();
    $graph = GraphBuilder::create('parallel-batch')
        ->addNode('start', PBStart::class)
        ->addNode('branch1', PBNode::class)
        ->addNode('branch2', PBNode::class)
        ->addNode('end', PBNode::class)
        ->addConditionalEdge('start', new class implements \Shahzad\AgenticGraph\Contracts\RouterInterface
        {
            public function route(\Shahzad\AgenticGraph\Contracts\StateContract $state): string|array
            {
                return ['branch1', 'branch2'];
            }

            public function getAvailableRoutes(): array
            {
                return ['branch1', 'branch2'];
            }

            public function getDefaultRoute(): ?string
            {
                return null;
            }

            public function validateRoute(string $route): bool
            {
                return in_array($route, ['branch1', 'branch2'], true);
            }
        })
        ->addEdge('branch1', 'end')
        ->addEdge('branch2', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register('parallel-batch', $graph);
    $job = new ExecuteGraphJob('parallel-batch', 'exec-batch', (new State)->toArray());
    $job->handle(new GraphExecutor, app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class));
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2);
});

it('dispatches continuation job after batch completion', function () {
    Bus::fake();
    config(['agenticgraph.checkpoint.enabled' => true]);

    $graph = GraphBuilder::create('parallel-batch-complete')
        ->addNode('start', PBStart::class)
        ->addNode('branch1', PBNode::class)
        ->addNode('branch2', PBNode::class)
        ->addNode('end', PBNode::class)
        ->addConditionalEdge('start', new class implements \Shahzad\AgenticGraph\Contracts\RouterInterface
        {
            public function route(\Shahzad\AgenticGraph\Contracts\StateContract $state): string|array
            {
                return ['branch1', 'branch2'];
            }

            public function getAvailableRoutes(): array
            {
                return ['branch1', 'branch2'];
            }

            public function getDefaultRoute(): ?string
            {
                return null;
            }

            public function validateRoute(string $route): bool
            {
                return in_array($route, ['branch1', 'branch2'], true);
            }
        })
        ->addJoin(['branch1', 'branch2'], 'end')
        ->addEdge('branch1', 'end')
        ->addEdge('branch2', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();

    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register('parallel-batch-complete', $graph);

    $executionId = 'exec-batch-complete';
    Cache::put('agenticgraph:batch:'.$executionId.':branch1', (new State(['branch' => 1]))->toArray());
    Cache::put('agenticgraph:batch:'.$executionId.':branch2', (new State(['branch' => 2]))->toArray());

    $coordinator = new ParallelExecutionCoordinator(
        new RoutingEngine(app()),
        app(StateManager::class),
        app(CacheManager::class),
        app(BusDispatcher::class),
        app(LoggerInterface::class),
        app(ExecutionRepository::class),
        app(Dispatcher::class),
        app(),
        app(CheckpointManager::class),
        config('agenticgraph.middleware.global'),
        static fn () => null,
        static fn () => null,
        static fn ($job) => dispatch($job)
    );
    $method = (new ReflectionClass($coordinator))->getMethod('handleBatchCompletion');
    $method->setAccessible(true);
    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-1';
    $method->invoke($coordinator, $batch, 'parallel-batch-complete', $executionId, 'start', ['branch1', 'branch2'], null);

    Bus::assertDispatched(ContinueExecutionJob::class, function ($job) use ($executionId) {
        return $job->executionId === $executionId
            && $job->startNode === 'end';
    });

    $storage = app(StorageInterface::class);
    $checkpoint = $storage->loadLatestForExecution($executionId);
    expect($checkpoint)->not->toBeNull()
        ->and($checkpoint->executionId)->toBe($executionId)
        ->and($checkpoint->currentNode)->toBe('start');
});
