<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\NodeInterrupted;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\NotYetCompleteException;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Jobs\ResumeExecutionJob;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

class ReconstructSyncResumeNode extends PrismNode
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
        return 'P2-T2 sync reconstruction resume';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $this->handleApproval(
            $state,
            $humanInput['approved'] ?? false,
            $humanInput['comments'] ?? null
        );
    }
}

class ReconstructSyncPublishNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('published', true);
    }
}

class ReconstructAsyncResumeNode extends PrismNode
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
        return 'P2-T2 async reconstruction resume';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $this->handleApproval(
            $state,
            $humanInput['approved'] ?? false,
            $humanInput['comments'] ?? null
        );
    }
}

class ReconstructNoInterruptNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('executed', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return false;
    }
}

function registerReconstructSyncGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('review', ReconstructSyncResumeNode::class)
        ->addNode('publish', ReconstructSyncPublishNode::class)
        ->addEdge('review', 'publish')
        ->setEntryPoint('review')
        ->addExitPoint('publish')
        ->build();

    AgenticGraph::register($name, $graph);
}

function registerReconstructAsyncGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('review', ReconstructAsyncResumeNode::class)
        ->setEntryPoint('review')
        ->addExitPoint('review')
        ->build();

    AgenticGraph::register($name, $graph);
}

function registerReconstructNoInterruptGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('start', ReconstructNoInterruptNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register($name, $graph);
}

function runReconstructAsyncWorker(string $executionId): void
{
    $job = null;

    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $dispatched) use ($executionId, &$job): bool {
        if ($dispatched->executionId !== $executionId) {
            return false;
        }

        $job = $dispatched;

        return true;
    });

    expect($job)->toBeInstanceOf(ExecuteGraphJob::class);

    $job->handle(app(GraphExecutor::class), app(GraphRegistry::class));
}

beforeEach(function () {
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
    config()->set('agenticgraph.execution.queue_connection', 'sync');
});

it('reconstructs originally-SYNC options and resumes in-process without dispatching a job', function () {
    Event::fake([NodeInterrupted::class]);

    $graphName = 'p2t2-sync-reconstruct';
    registerReconstructSyncGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE)
    );

    expect($handle->status())->toBe(ExecutionStatus::INTERRUPTED)
        ->and($handle->isSync())->toBeTrue();

    $reconstructed = AgenticGraph::getExecution($handle->id())->refresh();

    expect($reconstructed->isSync())->toBeTrue()
        ->and($reconstructed->isAsync())->toBeFalse()
        ->and($reconstructed->status())->toBe(ExecutionStatus::INTERRUPTED);

    Bus::fake();

    $resumed = $reconstructed->resume(['approved' => true, 'comments' => 'ok']);

    Bus::assertNotDispatched(ResumeExecutionJob::class);

    expect($resumed->status())->toBe(ExecutionStatus::COMPLETED)
        ->and($resumed->getFinalState()->get('published'))->toBeTrue();
});

it('reconstructs originally-ASYNC options and dispatches ResumeExecutionJob on resume', function () {
    Event::fake([NodeInterrupted::class]);
    Bus::fake();

    $graphName = 'p2t2-async-reconstruct';
    registerReconstructAsyncGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::NONE)
    );

    runReconstructAsyncWorker($handle->id());

    $reconstructed = AgenticGraph::getExecution($handle->id())->refresh();

    expect($reconstructed->isAsync())->toBeTrue()
        ->and($reconstructed->isSync())->toBeFalse()
        ->and($reconstructed->status())->toBe(ExecutionStatus::INTERRUPTED);

    $resumed = $reconstructed->resume(['approved' => true, 'comments' => 'ok']);

    Bus::assertDispatched(ResumeExecutionJob::class);

    expect($resumed->status())->toBe(ExecutionStatus::RUNNING)
        ->and(fn () => $resumed->getFinalState())->toThrow(NotYetCompleteException::class);
});

it('reconstructs interrupts false from persisted execution_options', function () {
    Bus::fake();

    $graphName = 'p2t2-interrupts-false-reconstruct';
    registerReconstructNoInterruptGraph($graphName);

    $expected = new ExecutionOptions(
        mode: ExecutionMode::ASYNC,
        streaming: StreamingMode::NONE,
        interrupts: false
    );

    $handle = AgenticGraph::execute($graphName, new State, $expected);

    runReconstructAsyncWorker($handle->id());

    AgenticGraph::getExecution($handle->id());

    $record = app(\Shahzad\AgenticGraph\Execution\ExecutionStore::class)->get($handle->id());

    expect($record)->not->toBeNull()
        ->and($record->executionOptions)->toEqual($expected)
        ->and($record->executionOptions?->interrupts)->toBeFalse();
});

it('reconstructs sane defaults for null execution_options without throwing', function () {
    DB::table('agenticgraph_executions')->insert([
        'id' => 'exec-legacy-null-options',
        'graph_name' => 'legacy',
        'status' => 'completed',
        'initial_state' => json_encode([]),
        'final_state' => json_encode(['done' => true]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $handle = AgenticGraph::getExecution('exec-legacy-null-options');

    expect($handle->isSync())->toBeTrue()
        ->and($handle->isAsync())->toBeFalse()
        ->and($handle->status())->toBe(ExecutionStatus::COMPLETED);
});

it('hydrates interrupted final state from checkpoint and reports correct SYNC mode', function () {
    Event::fake([NodeInterrupted::class]);

    $graphName = 'p2t2-interrupted-checkpoint-mode';
    registerReconstructSyncGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE)
    );

    $reconstructed = AgenticGraph::getExecution($handle->id())->refresh();
    $finalState = $reconstructed->getFinalState();

    expect($reconstructed->isSync())->toBeTrue()
        ->and($reconstructed->status())->toBe(ExecutionStatus::INTERRUPTED)
        ->and($finalState->hasInterrupt())->toBeTrue()
        ->and($finalState->getInterruptCheckpointId())->not->toBeEmpty();
});
