<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\NodeInterrupted;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
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

class AsyncResumeApprovalNode extends PrismNode
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
        return 'Async resume dispatch regression';
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

class SyncResumeApprovalNode extends PrismNode
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
        return 'Sync resume control regression';
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

class SyncResumePublishNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('published', true);
    }
}

function registerAsyncResumeGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('review', AsyncResumeApprovalNode::class)
        ->setEntryPoint('review')
        ->addExitPoint('review')
        ->build();

    AgenticGraph::register($name, $graph);
}

function registerSyncResumeGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('review', SyncResumeApprovalNode::class)
        ->addNode('publish', SyncResumePublishNode::class)
        ->addEdge('review', 'publish')
        ->setEntryPoint('review')
        ->addExitPoint('publish')
        ->build();

    AgenticGraph::register($name, $graph);
}

function runAsyncInterruptWorker(string $executionId, string $graphName): void
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

it('dispatches ResumeExecutionJob for async handle resume without inline execution', function () {
    Event::fake([NodeInterrupted::class]);
    Bus::fake();

    $graphName = 'async-handle-resume-dispatch';
    registerAsyncResumeGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::ASYNC,
            streaming: StreamingMode::NONE,
            interrupts: true
        )
    );

    runAsyncInterruptWorker($handle->id(), $graphName);

    $handle = $handle->refresh();

    expect($handle->status())->toBe(ExecutionStatus::INTERRUPTED)
        ->and($handle->isAsync())->toBeTrue();

    $resumed = $handle->resume(['approved' => true, 'comments' => 'ok']);

    Bus::assertDispatched(ResumeExecutionJob::class);

    expect($resumed->status())->toBe(ExecutionStatus::RUNNING)
        ->and($resumed->status())->not->toBe(ExecutionStatus::COMPLETED)
        ->and($resumed->isAsync())->toBeTrue()
        ->and($resumed->id())->toBe($handle->id())
        ->and(app(ExecutionStore::class)->get($handle->id())?->status)->toBe(ExecutionStatus::INTERRUPTED);

    expect(fn () => $resumed->getFinalState())->toThrow(NotYetCompleteException::class);
});

it('resumes sync interrupted handle in-process without dispatching a job', function () {
    Event::fake([NodeInterrupted::class]);

    $graphName = 'sync-handle-resume-inline';
    registerSyncResumeGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::NONE,
            interrupts: true
        )
    );

    expect($handle->status())->toBe(ExecutionStatus::INTERRUPTED)
        ->and($handle->isSync())->toBeTrue();

    Bus::fake();

    $resumed = $handle->resume(['approved' => true, 'comments' => 'ok']);

    Bus::assertNotDispatched(ResumeExecutionJob::class);

    expect($resumed->status())->toBe(ExecutionStatus::COMPLETED)
        ->and($resumed->getFinalState()->get('published'))->toBeTrue()
        ->and($resumed->getFinalState()->get('approved'))->toBeTrue();
});
