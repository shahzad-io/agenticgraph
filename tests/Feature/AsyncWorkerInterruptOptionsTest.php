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
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

class AsyncWorkerInterruptNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('executed', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return true;
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Async worker interrupt-options regression';
    }
}

function configureAsyncWorkerInterruptTest(): void
{
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    config()->set('agenticgraph.checkpoint.enabled', true);
}

function registerAsyncWorkerInterruptGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('start', AsyncWorkerInterruptNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register($name, $graph);
}

function runCapturedAsyncWorkerJob(string $executionId): ExecuteGraphJob
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

    $job->handle(
        app(GraphExecutor::class),
        app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)
    );

    return $job;
}

it('ASYNC+NONE worker completes without interrupting when interrupts is false', function () {
    Event::fake([NodeInterrupted::class]);
    Bus::fake();
    configureAsyncWorkerInterruptTest();
    registerAsyncWorkerInterruptGraph('async-worker-interrupts-false');

    $handle = AgenticGraph::execute(
        'async-worker-interrupts-false',
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::ASYNC,
            streaming: StreamingMode::NONE,
            interrupts: false
        )
    );

    expect(app(ExecutionStore::class)->get($handle->id())?->status)
        ->toBe(ExecutionStatus::QUEUED);

    $job = null;

    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $dispatched) use ($handle, &$job): bool {
        if ($dispatched->executionId !== $handle->id()) {
            return false;
        }

        $job = $dispatched;

        return true;
    });

    expect($job)->toBeInstanceOf(ExecuteGraphJob::class)
        ->and($job->executionOptions)->not->toBeNull()
        ->and($job->executionOptions->interrupts)->toBeFalse();

    $job->handle(
        app(GraphExecutor::class),
        app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)
    );

    $record = app(ExecutionStore::class)->get($handle->id());
    $finalState = AgenticGraph::getExecution($handle->id())->refresh()->getFinalState();

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ExecutionStatus::COMPLETED)
        ->and($finalState->get('executed'))->toBeTrue();

    Event::assertNotDispatched(NodeInterrupted::class);
});

it('ASYNC+NONE worker interrupts when interrupts is true', function () {
    Event::fake([NodeInterrupted::class]);
    Bus::fake();
    configureAsyncWorkerInterruptTest();
    registerAsyncWorkerInterruptGraph('async-worker-interrupts-true');

    $handle = AgenticGraph::execute(
        'async-worker-interrupts-true',
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::ASYNC,
            streaming: StreamingMode::NONE,
            interrupts: true
        )
    );

    runCapturedAsyncWorkerJob($handle->id());

    $record = app(ExecutionStore::class)->get($handle->id());

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ExecutionStatus::INTERRUPTED);

    Event::assertDispatched(NodeInterrupted::class);
});
