<?php

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Shahzad\AgenticGraph\Contracts\ParallelExecutorInterface;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\NodeMiddlewarePipeline;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

class CadencePassNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class CadenceReviewNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('reviewed', true);
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

class CadenceParallelRouter implements RouterInterface
{
    public function route(StateContract $state): string|array
    {
        return ['p1', 'p2'];
    }

    public function getAvailableRoutes(): array
    {
        return ['p1', 'p2'];
    }

    public function getDefaultRoute(): ?string
    {
        return null;
    }

    public function validateRoute(string $route): bool
    {
        return in_array($route, ['p1', 'p2'], true);
    }
}

function configureCheckpointCadenceTests(): void
{
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.checkpoint.frequency', 3);
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    app()->forgetInstance(CheckpointManager::class);
}

function checkpointNodesForExecution(string $executionId): array
{
    return DB::table('agenticgraph_checkpoints')
        ->where('execution_id', $executionId)
        ->orderBy('created_at')
        ->pluck('current_node')
        ->all();
}

function registerCadenceBatchGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('n1', CadencePassNode::class)
        ->addNode('n2', CadencePassNode::class)
        ->addNode('n3', CadencePassNode::class)
        ->addNode('split', CadencePassNode::class)
        ->addNode('p1', CadencePassNode::class)
        ->addNode('p2', CadencePassNode::class)
        ->addNode('join', CadencePassNode::class)
        ->addNode('n4', CadencePassNode::class)
        ->addNode('n5', CadencePassNode::class)
        ->addNode('n6', CadencePassNode::class)
        ->addEdge('n1', 'n2')
        ->addEdge('n2', 'n3')
        ->addEdge('n3', 'split')
        ->addConditionalEdge('split', new CadenceParallelRouter)
        ->addJoin(['p1', 'p2'], 'join')
        ->addEdge('p1', 'join')
        ->addEdge('p2', 'join')
        ->addEdge('join', 'n4')
        ->addEdge('n4', 'n5')
        ->addEdge('n5', 'n6')
        ->setEntryPoint('n1')
        ->addExitPoint('n6')
        ->build();

    AgenticGraph::register($name, $graph);
}

function registerCadenceInterruptGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('n1', CadencePassNode::class)
        ->addNode('n2', CadencePassNode::class)
        ->addNode('n3', CadencePassNode::class)
        ->addNode('review', CadenceReviewNode::class)
        ->addNode('n4', CadencePassNode::class)
        ->addNode('n5', CadencePassNode::class)
        ->addNode('n6', CadencePassNode::class)
        ->addEdge('n1', 'n2')
        ->addEdge('n2', 'n3')
        ->addEdge('n3', 'review')
        ->addEdge('review', 'n4')
        ->addEdge('n4', 'n5')
        ->addEdge('n5', 'n6')
        ->setEntryPoint('n1')
        ->addExitPoint('n6')
        ->build();

    AgenticGraph::register($name, $graph);
}

function runCadenceGraphAcrossBatchBoundary(string $graphName, string $executionId): void
{
    Bus::fake();

    $job = new ExecuteGraphJob(
        graphName: $graphName,
        executionId: $executionId,
        stateData: (new State)->toArray(),
    );
    $job->handle(app(GraphExecutor::class), app(GraphRegistry::class));

    $registry = app(GraphRegistry::class);

    Bus::assertBatched(function ($batch) use ($graphName, $executionId, $registry): bool {
        foreach ($batch->jobs as $nodeJob) {
            expect($nodeJob)->toBeInstanceOf(ExecuteNodeJob::class);
            $nodeJob->handle($registry, app(NodeMiddlewarePipeline::class));
        }

        $coordinator = app(ParallelExecutorInterface::class);
        $method = (new ReflectionClass($coordinator))->getMethod('handleBatchCompletion');
        $method->setAccessible(true);
        $mockBatch = Mockery::mock(Batch::class);
        $mockBatch->id = 'cadence-batch-'.Str::uuid();
        $method->invoke($coordinator, $mockBatch, $graphName, $executionId, 'split', ['p1', 'p2'], null);

        return $batch->jobs->count() === 2;
    });

    $continueJob = null;
    Bus::assertDispatched(ContinueExecutionJob::class, function (ContinueExecutionJob $dispatched) use ($executionId, &$continueJob): bool {
        if ($dispatched->executionId !== $executionId) {
            return false;
        }
        $continueJob = $dispatched;

        return true;
    });

    expect($continueJob)->toBeInstanceOf(ContinueExecutionJob::class);
    app()->forgetInstance(CheckpointManager::class);
    $continueJob->handle(app(GraphExecutor::class), app(GraphRegistry::class));
}

it('keeps checkpoint cadence across a real parallel batch boundary', function () {
    configureCheckpointCadenceTests();
    $graphName = 'cadence-batch-'.Str::uuid();
    registerCadenceBatchGraph($graphName);
    $graph = app(GraphRegistry::class)->get($graphName);

    $syncExecutionId = (string) Str::uuid();
    app(GraphExecutor::class)->execute(
        $graph,
        new State,
        new ExecutionOptions,
        useBatch: false,
        executionId: $syncExecutionId
    );
    $expectedNodes = checkpointNodesForExecution($syncExecutionId);

    $batchExecutionId = (string) Str::uuid();
    runCadenceGraphAcrossBatchBoundary($graphName, $batchExecutionId);

    expect(checkpointNodesForExecution($batchExecutionId))->toBe($expectedNodes)
        ->and($expectedNodes)->toBe(['n3', 'join', 'n6']);
});

it('does not shift frequency cadence after a forced interrupt save', function () {
    configureCheckpointCadenceTests();

    $graphName = 'cadence-interrupt-'.Str::uuid();
    registerCadenceInterruptGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::NONE,
            interrupts: true
        )
    );

    expect(checkpointNodesForExecution($handle->id()))->toBe(['n3', 'review'])
        ->and(app(CheckpointManager::class)->getNodeCount($handle->id()))->toBe(3);

    $resumed = $handle->resume(['approved' => true]);

    expect(checkpointNodesForExecution($handle->id()))->toBe(['n3', 'review', 'n5'])
        ->and(app(ExecutionRepository::class)->getCheckpointNodeCount($handle->id()))->toBe(7);
});

it('does not increment the frequency counter on forced interrupt saves', function () {
    configureCheckpointCadenceTests();

    $manager = app(CheckpointManager::class);
    $executionId = (string) Str::uuid();
    app(ExecutionRepository::class)->queued($executionId, 'counter-test', []);

    $checkpoint = new Checkpoint;
    $checkpoint->graphName = 'counter-test';
    $checkpoint->currentNode = 'review';
    $checkpoint->state = new State;

    $manager->save($executionId, $checkpoint);
    $manager->save($executionId, $checkpoint);
    expect($manager->getNodeCount($executionId))->toBe(2);

    $manager->saveInterrupt($executionId, $checkpoint);

    expect($manager->getNodeCount($executionId))->toBe(2)
        ->and(app(ExecutionRepository::class)->getCheckpointNodeCount($executionId))->toBe(2);
});

afterEach(function () {
    Mockery::close();
});
