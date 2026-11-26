<?php

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\InterruptExpired;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\NodeMiddlewarePipeline;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Jobs\ResumeExecutionJob;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Storage\FileStorage;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamResumption;

/**
 * A run whose row was deleted (the app removed it while its job waited in the queue) must stay deleted: no queued job
 * runs a node, throws, or writes a row, stream event, checkpoint or batch result for it.
 */
class DeletedRunNode extends PrismNode
{
    public static int $runs = 0;

    public function execute(StateContract $state): StateContract
    {
        self::$runs++;

        return $state->set('done', true);
    }
}

class DeletedRunApprovalNode extends DeletedRunNode
{
    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->has('approved');
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Needs approval';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $state->set('approved', true);
    }
}

class DeletedRunThrowingNode extends DeletedRunNode
{
    public function execute(StateContract $state): StateContract
    {
        throw new RuntimeException('node failed');
    }
}

function deletedGraph(string $node = DeletedRunNode::class): void
{
    AgenticGraph::register('deleted-run', GraphBuilder::create('deleted-run')
        ->addNode('work', $node)
        ->setEntryPoint('work')
        ->addExitPoint('work')
        ->build());
}

function deletedOptions(StreamingMode $streaming): ExecutionOptions
{
    return new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: $streaming, interrupts: true);
}

/** Queues a run through the public API and returns the job a worker would take. */
function deletedQueuedJob(StreamingMode $streaming): ExecuteGraphJob
{
    Bus::fake();
    $handle = AgenticGraph::execute('deleted-run', new State, deletedOptions($streaming));

    return Bus::dispatched(ExecuteGraphJob::class)->first(fn (ExecuteGraphJob $job) => $job->executionId === $handle->id());
}

/** A running execution with a queued continuation, as after a parallel join. */
function deletedContinueJob(StreamingMode $streaming): ContinueExecutionJob
{
    deletedGraph();
    $id = 'exec-deleted-continue';
    $repo = app(ExecutionRepository::class);
    $repo->queued($id, 'deleted-run', [], null, deletedOptions($streaming));
    $repo->started($id, 'deleted-run');

    return new ContinueExecutionJob('deleted-run', $id, (new State)->toArray(), 'work', executionOptions: deletedOptions($streaming), streamId: 'stream-deleted-continue', streamSeq: 0);
}

/**
 * An interrupted run with its queued resume job. File checkpoints survive the delete of the run row; database ones
 * cascade with it.
 */
function deletedResumeJob(StreamingMode $streaming): ResumeExecutionJob
{
    deletedGraph(DeletedRunApprovalNode::class);
    $job = deletedQueuedJob($streaming);
    app()->call([$job, 'handle']);
    AgenticGraph::getExecution($job->executionId)->resume(['approved' => true]);

    return Bus::dispatched(ResumeExecutionJob::class)->first();
}

function deletedRunId(ResumeExecutionJob $job): string
{
    return (string) InterruptRecord::find($job->interruptId)?->executionId();
}

function deletedRunRow(string $executionId): void
{
    DB::table('agenticgraph_executions')->where('id', $executionId)->delete();
}

/** @return array<string, int> everything stored for one execution */
function deletedFootprint(string $executionId): array
{
    $count = fn (string $table, string $column = 'execution_id'): int => DB::table($table)->where($column, $executionId)->count();

    return [
        'row' => $count('agenticgraph_executions', 'id'),
        'events' => $count('stream_events'),
        'checkpoints' => $count('agenticgraph_checkpoints'),
        'file_checkpoints' => count(glob(test()->checkpointDir.'/*.json') ?: []),
        'batch_results' => $count('agenticgraph_batch_results'),
    ];
}

/**
 * Deletes the row right after the job first reads it: after the job's own check, before a coordinator looks the run
 * up again.
 */
function deletedAfterFirstRead(string $executionId): stdClass
{
    $race = (object) ['deleted' => false];
    DB::listen(function ($query) use ($executionId, $race): void {
        if (! $race->deleted && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'agenticgraph_executions')) {
            $race->deleted = true;
            deletedRunRow($executionId);
        }
    });

    return $race;
}

function deletedResumeHandle(ResumeExecutionJob $job): void
{
    $job->handle(app(InterruptServiceInterface::class), app(StreamResumption::class));
}

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON');
    expect((int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);

    $this->checkpointDir = sys_get_temp_dir().'/ag-deleted-'.bin2hex(random_bytes(6));
    app()->bind(FileStorage::class, fn () => new FileStorage($this->checkpointDir));
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    config()->set('agenticgraph.streaming.store.driver', 'database');
    app()->forgetInstance(StreamStoreInterface::class);
    DeletedRunNode::$runs = 0;
    deletedGraph();
});

afterEach(function () {
    foreach (glob($this->checkpointDir.'/{,.}*.json', GLOB_BRACE) ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->checkpointDir);
});

dataset('deleted streaming', ['no stream' => [StreamingMode::NONE], 'sse' => [StreamingMode::SSE]]);

it('ExecuteGraphJob does not run a deleted run', function (StreamingMode $streaming) {
    $job = deletedQueuedJob($streaming);
    deletedRunRow($job->executionId);

    app()->call([$job, 'handle']);

    expect(DeletedRunNode::$runs)->toBe(0)
        ->and(array_sum(deletedFootprint($job->executionId)))->toBe(0);
})->with('deleted streaming');

it('ContinueExecutionJob does not run a deleted run', function (StreamingMode $streaming) {
    $job = deletedContinueJob($streaming);
    deletedRunRow($job->executionId);

    app()->call([$job, 'handle']);

    expect(DeletedRunNode::$runs)->toBe(0)
        ->and(array_sum(deletedFootprint($job->executionId)))->toBe(0);
})->with('deleted streaming');

it('ExecuteNodeJob does not run a branch of a deleted run', function () {
    $job = new ExecuteNodeJob('exec-deleted-branch', 'work', (new State)->toArray(), 'deleted-run');

    $job->handle(app(GraphRegistry::class), app(NodeMiddlewarePipeline::class));

    expect(DeletedRunNode::$runs)->toBe(0)
        ->and(array_sum(deletedFootprint('exec-deleted-branch')))->toBe(0);
});

it('ResumeExecutionJob does not resume a deleted run', function (StreamingMode $streaming, string $storage) {
    config()->set('agenticgraph.storage', $storage);
    $job = deletedResumeJob($streaming);
    $id = deletedRunId($job);
    deletedRunRow($id);
    $before = deletedFootprint($id);

    deletedResumeHandle($job);

    expect(DeletedRunNode::$runs)->toBe(0)
        ->and(deletedFootprint($id))->toBe($before)
        ->and($before['row'])->toBe(0);
})->with('deleted streaming')->with(['database', 'file']);

it('ExecuteGraphJob::failed() writes nothing for a deleted run', function () {
    Event::fake([GraphExecutionFailed::class]);
    $job = deletedQueuedJob(StreamingMode::SSE);
    deletedRunRow($job->executionId);

    $job->failed(new RuntimeException('worker failed'));

    expect(array_sum(deletedFootprint($job->executionId)))->toBe(0);
    Event::assertNotDispatched(GraphExecutionFailed::class);
});

it('ResumeExecutionJob::failed() writes nothing for a deleted run', function () {
    Event::fake([GraphExecutionFailed::class]);
    $job = deletedResumeJob(StreamingMode::SSE);
    $id = deletedRunId($job);
    deletedRunRow($id);
    $before = deletedFootprint($id);

    $job->failed(new RuntimeException('worker failed'));

    expect(deletedFootprint($id))->toBe($before);
    Event::assertNotDispatched(GraphExecutionFailed::class);
});

it('batch completion writes nothing for a deleted run', function () {
    Bus::fake();
    $id = 'exec-deleted-batch';
    $repo = app(ExecutionRepository::class);
    $repo->queued($id, 'deleted-run');
    $repo->putBatchResult($id, 'work', ['done' => true]);
    deletedRunRow($id);
    $before = deletedFootprint($id);
    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-deleted';

    app(ParallelExecutionCoordinator::class)->handleBatchCompletion($batch, 'deleted-run', $id, 'work', ['work']);

    expect(deletedFootprint($id))->toBe($before);
    Bus::assertNotDispatched(ContinueExecutionJob::class);
});

it('interrupt:expire writes no stream event and fires no InterruptExpired for a deleted run', function () {
    $job = deletedResumeJob(StreamingMode::SSE);
    $id = deletedRunId($job);
    InterruptRecord::find($job->interruptId)->update(['status' => 'pending', 'expires_at' => now()->subMinute()]);
    deletedRunRow($id);
    $before = deletedFootprint($id);
    Event::fake([InterruptExpired::class, GraphExecutionFailed::class]);

    $this->artisan('agenticgraph:interrupt:expire')->assertSuccessful();

    expect(deletedFootprint($id))->toBe($before)
        ->and(InterruptRecord::find($job->interruptId)->status())->toBe('expired');
    Event::assertNotDispatched(InterruptExpired::class);
    Event::assertNotDispatched(GraphExecutionFailed::class);
});

// Review decision 2: the row is deleted after the job check and before the coordinator lookup.
it('ExecuteGraphJob creates nothing when the row goes after its check', function (StreamingMode $streaming) {
    $job = deletedQueuedJob($streaming);
    $race = deletedAfterFirstRead($job->executionId);

    app()->call([$job, 'handle']);

    expect($race->deleted)->toBeTrue()
        ->and(DeletedRunNode::$runs)->toBe(0)
        ->and(array_sum(deletedFootprint($job->executionId)))->toBe(0);
})->with('deleted streaming');

it('ContinueExecutionJob creates nothing when the row goes after its check', function (StreamingMode $streaming) {
    $job = deletedContinueJob($streaming);
    $race = deletedAfterFirstRead($job->executionId);

    app()->call([$job, 'handle']);

    expect($race->deleted)->toBeTrue()
        ->and(DeletedRunNode::$runs)->toBe(0)
        ->and(array_sum(deletedFootprint($job->executionId)))->toBe(0);
})->with('deleted streaming');

it('ResumeExecutionJob creates nothing when the row goes after its check', function (StreamingMode $streaming) {
    config()->set('agenticgraph.storage', 'file');
    $job = deletedResumeJob($streaming);
    $id = deletedRunId($job);
    $before = deletedFootprint($id);
    $race = deletedAfterFirstRead($id);

    deletedResumeHandle($job);

    expect($race->deleted)->toBeTrue()
        ->and(DeletedRunNode::$runs)->toBe(0)
        ->and(deletedFootprint($id))->toBe(['row' => 0] + $before);
})->with('deleted streaming');

// A worker must not see the job before the row it needs is committed.
it('dispatches a queued run only after the transaction commits', function (StreamingMode $streaming) {
    DB::beginTransaction();
    $handle = AgenticGraph::execute('deleted-run', new State, deletedOptions($streaming));
    $ranInside = DeletedRunNode::$runs;
    DB::commit();

    expect($ranInside)->toBe(0)
        ->and(DeletedRunNode::$runs)->toBe(1)
        ->and(app(ExecutionRepository::class)->getStatus($handle->id()))->toBe('completed');
})->with('deleted streaming');

it('never dispatches a queued run after a rollback', function (StreamingMode $streaming) {
    DB::beginTransaction();
    $handle = AgenticGraph::execute('deleted-run', new State, deletedOptions($streaming));
    DB::rollBack();

    expect(DeletedRunNode::$runs)->toBe(0)
        ->and(array_sum(deletedFootprint($handle->id())))->toBe(0);
})->with('deleted streaming');

// A worker process goes on after a failed job: the job must not leave its run marked as queued.
it('clears the queued-run mark when the job throws, so a later queued() behaves as normal', function (StreamingMode $streaming) {
    deletedGraph(DeletedRunThrowingNode::class);
    $job = deletedQueuedJob($streaming);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'node failed');
    deletedRunRow($job->executionId);
    $repo = app(ExecutionRepository::class);

    expect($repo->isDeletedQueuedRun($job->executionId))->toBeFalse();
    $repo->queued($job->executionId, 'deleted-run');

    expect($repo->getStatus($job->executionId))->toBe('queued');
})->with('deleted streaming');

// Control: synchronous runs keep creating their rows.
it('a synchronous run with no existing row still creates it and completes', function (StreamingMode $streaming) {
    $handle = AgenticGraph::execute('deleted-run', new State, new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: $streaming));
    app(GraphExecutor::class)->execute(AgenticGraph::getOrBuild('deleted-run'), new State, executionId: 'exec-sync-own-id');

    expect(DeletedRunNode::$runs)->toBe(2)
        ->and(app(ExecutionRepository::class)->getStatus($handle->id()))->toBe('completed')
        ->and(app(ExecutionRepository::class)->getStatus('exec-sync-own-id'))->toBe('completed');
})->with('deleted streaming');
