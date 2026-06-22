<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

class PersistenceSimpleNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('done', true);
    }
}

function registerPersistenceGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('start', PersistenceSimpleNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register($name, $graph);
}

function persistedExecutionOptions(string $executionId): ?ExecutionOptions
{
    $json = DB::table('agenticgraph_executions')->where('id', $executionId)->value('execution_options');
    if ($json === null) {
        return null;
    }

    return ExecutionOptions::fromArray(json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR));
}

function runCapturedPersistenceJob(): ExecuteGraphJob
{
    $job = null;

    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $dispatched) use (&$job) {
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

beforeEach(function () {
    config()->set('agenticgraph.execution.queue_connection', 'sync');
});

it('persists resolved SYNC options after execution', function () {
    registerPersistenceGraph('p2t1-sync');

    $expected = new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE);
    $handle = AgenticGraph::execute('p2t1-sync', new State, $expected);

    $persisted = persistedExecutionOptions($handle->id());

    expect($persisted)->toEqual($expected);
});

it('persists resolved ASYNC+NONE options after dispatch and job run', function () {
    Bus::fake();
    registerPersistenceGraph('p2t1-async-none');

    $expected = new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::NONE);
    $handle = AgenticGraph::execute('p2t1-async-none', new State, $expected);

    $queued = persistedExecutionOptions($handle->id());
    expect($queued)->toEqual($expected);

    $job = runCapturedPersistenceJob();
    $persisted = persistedExecutionOptions($job->executionId);

    expect($persisted)->toEqual($expected);
});

it('persists resolved ASYNC+BROADCAST options after dispatch and job run', function () {
    Bus::fake();
    registerPersistenceGraph('p2t1-async-broadcast');

    $expected = new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::BROADCAST);
    $handle = AgenticGraph::execute('p2t1-async-broadcast', new State, $expected);

    $queued = persistedExecutionOptions($handle->id());
    expect($queued)->toEqual($expected);

    $job = runCapturedPersistenceJob();
    $persisted = persistedExecutionOptions($job->executionId);

    expect($persisted)->toEqual($expected);
});

it('persists interrupts false instead of the default true', function () {
    Bus::fake();
    registerPersistenceGraph('p2t1-interrupts-false');

    $expected = new ExecutionOptions(
        mode: ExecutionMode::ASYNC,
        streaming: StreamingMode::NONE,
        interrupts: false
    );
    $handle = AgenticGraph::execute('p2t1-interrupts-false', new State, $expected);

    expect(persistedExecutionOptions($handle->id()))->toEqual($expected);

    $job = runCapturedPersistenceJob();

    expect(persistedExecutionOptions($job->executionId))->toEqual($expected)
        ->and($job->executionOptions?->interrupts)->toBeFalse();
});

it('writes execution_options from queued and started repository methods', function () {
    $repository = app(ExecutionRepository::class);
    $options = new ExecutionOptions(
        mode: ExecutionMode::ASYNC,
        streaming: StreamingMode::SSE,
        interrupts: false,
        estimatedDurationSeconds: 9
    );

    $repository->queued('exec-repo-queued', 'graph', ['x' => 1], 'stream-1', $options);

    expect(persistedExecutionOptions('exec-repo-queued'))->toEqual($options);

    $repository->started('exec-repo-queued', 'graph', ['x' => 1], 'stream-1', $options);

    expect(persistedExecutionOptions('exec-repo-queued'))->toEqual($options);
});

it('adds execution_options via zzzz migration with hasColumn guard', function () {
    Schema::table('agenticgraph_executions', function ($table) {
        $table->dropColumn('execution_options');
    });

    expect(Schema::hasColumn('agenticgraph_executions', 'execution_options'))->toBeFalse();

    $migration = include __DIR__.'/../../database/migrations/zzzz_add_execution_options_to_agenticgraph_executions_table.php';
    $migration->up();

    expect(Schema::hasColumn('agenticgraph_executions', 'execution_options'))->toBeTrue();

    $migration->up();

    expect(Schema::hasColumn('agenticgraph_executions', 'execution_options'))->toBeTrue();
});

it('includes execution_options in the fresh install executions table stub', function () {
    $stub = file_get_contents(__DIR__.'/../../database/migrations/create_agenticgraph_executions_table.php.stub');

    expect($stub)->toContain("json('execution_options')");
});
