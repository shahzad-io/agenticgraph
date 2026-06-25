<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

class AsyncGraphStartedNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('done', true);
    }
}

function configureAsyncGraphStartedTest(): void
{
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    config()->set('agenticgraph.streaming.store.driver', 'database');
}

function registerAsyncGraphStartedFixture(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('start', AsyncGraphStartedNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register($name, $graph);
}

function runCapturedExecuteGraphJob(): ExecuteGraphJob
{
    $job = null;

    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $dispatched) use (&$job) {
        $job = $dispatched;

        return true;
    });

    expect($job)->toBeInstanceOf(ExecuteGraphJob::class);

    $job->handle(
        app(GraphExecutor::class),
        app(GraphRegistry::class)
    );

    return $job;
}

it('dispatches GraphExecutionStarted exactly once for ASYNC+NONE', function () {
    Event::fake([GraphExecutionStarted::class]);
    Bus::fake();
    configureAsyncGraphStartedTest();

    registerAsyncGraphStartedFixture('async-none-started');

    $handle = AgenticGraph::execute(
        'async-none-started',
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::NONE)
    );

    $record = app(ExecutionStore::class)->get($handle->id());

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ExecutionStatus::QUEUED);

    Event::assertNotDispatched(GraphExecutionStarted::class);

    runCapturedExecuteGraphJob();

    Event::assertDispatchedTimes(GraphExecutionStarted::class, 1);
});

it('dispatches GraphExecutionStarted exactly once for ASYNC+BROADCAST', function () {
    Event::fake([GraphExecutionStarted::class]);
    Bus::fake();
    configureAsyncGraphStartedTest();

    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->zeroOrMoreTimes();
    app()->instance(Broadcaster::class, $broadcaster);

    registerAsyncGraphStartedFixture('async-broadcast-started');

    $handle = AgenticGraph::execute(
        'async-broadcast-started',
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::BROADCAST)
    );

    $record = app(ExecutionStore::class)->get($handle->id());

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ExecutionStatus::QUEUED);

    Event::assertNotDispatched(GraphExecutionStarted::class);

    runCapturedExecuteGraphJob();

    Event::assertDispatchedTimes(GraphExecutionStarted::class, 1);
});
