<?php

use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Bus\UpdatedBatchJobCounts;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

/**
 * A branch job of an async fan-out that fails for good ends the execution as failed, also when Laravel's batch
 * bookkeeping fails (the job_batches update throws, as "database is locked" does on SQLite) and the batch callbacks
 * never run. A real batch runs on the database queue, worked by queue:work, with the database batch repository (the
 * sync queue runs batch jobs inside the job_batches transaction, so it would roll the failure back).
 */
class PBFBranchOk extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('ok_done', true);
    }
}

class PBFBranchBoom extends AbstractNode
{
    public function getRetryAttempts(): int
    {
        return 1;
    }

    public function execute(StateContract $state): StateContract
    {
        throw new RuntimeException('branch boom');
    }
}

class PBFPassNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class PBFFork implements RouterInterface
{
    public function route(StateContract $state): string|array
    {
        return ['ok', 'boom'];
    }

    public function getAvailableRoutes(): array
    {
        return ['ok', 'boom'];
    }

    public function getDefaultRoute(): ?string
    {
        return null;
    }

    public function validateRoute(string $route): bool
    {
        return in_array($route, ['ok', 'boom'], true);
    }
}

/** The database batch repository with its job_batches counter updates failing, as SQLite does under lock contention. */
class PBFLockedBatchRepository extends DatabaseBatchRepository
{
    public static bool $locked = true;

    public function decrementPendingJobs(string $batchId, string $jobId): UpdatedBatchJobCounts
    {
        return self::$locked ? throw new RuntimeException('SQLSTATE[HY000]: General error: 5 database is locked') : parent::decrementPendingJobs($batchId, $jobId);
    }

    public function incrementFailedJobs(string $batchId, string $jobId): UpdatedBatchJobCounts
    {
        return self::$locked ? throw new RuntimeException('SQLSTATE[HY000]: General error: 5 database is locked') : parent::incrementFailedJobs($batchId, $jobId);
    }
}

/** Fans out to $branches through a real batch and works the queue until it is empty. */
function pbfFanOut(string $executionId, array $branches): void
{
    $graph = app(GraphRegistry::class)->get('pbf');
    $repo = app(ExecutionRepository::class);
    $repo->queued($executionId, 'pbf');
    $repo->started($executionId, 'pbf');

    app(ParallelExecutionCoordinator::class)->executeParallelViaBatch($graph, $branches, new State(['topic' => 'demo']), $executionId);
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'agenticgraph', '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0]);
}

beforeEach(function () {
    config()->set('agenticgraph.checkpoint.enabled', false);
    config()->set('agenticgraph.execution.queue_connection', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
    config()->set('queue.failed.driver', 'null');
    config()->set('queue.batching.database', 'testing');
    Schema::create('jobs', function (Blueprint $t) {
        $t->id();
        $t->string('queue')->index();
        $t->longText('payload');
        $t->unsignedTinyInteger('attempts');
        $t->unsignedInteger('reserved_at')->nullable();
        $t->unsignedInteger('available_at');
        $t->unsignedInteger('created_at');
    });
    Schema::create('job_batches', function (Blueprint $t) {
        $t->string('id')->primary();
        $t->string('name');
        $t->integer('total_jobs');
        $t->integer('pending_jobs');
        $t->integer('failed_jobs');
        $t->longText('failed_job_ids');
        $t->mediumText('options')->nullable();
        $t->integer('cancelled_at')->nullable();
        $t->integer('created_at');
        $t->integer('finished_at')->nullable();
    });
    PBFLockedBatchRepository::$locked = true;
    app()->singleton(BatchRepository::class, fn ($app) => new PBFLockedBatchRepository($app->make('Illuminate\Bus\BatchFactory'), $app->make('db')->connection('testing'), 'job_batches'));
    app(GraphRegistry::class)->register('pbf', GraphBuilder::create('pbf')
        ->addNode('start', PBFPassNode::class)
        ->addNode('ok', PBFBranchOk::class)
        ->addNode('boom', PBFBranchBoom::class)
        ->addNode('end', PBFPassNode::class)
        ->addConditionalEdge('start', PBFFork::class)
        ->addEdge('ok', 'end')
        ->addEdge('boom', 'end')
        ->addJoin(['ok', 'boom'], 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build());
});

it('fails the execution when a branch fails and the batch cannot record it', function () {
    Event::fake([GraphExecutionFailed::class]);

    pbfFanOut('exec-pbf-boom', ['boom']);

    expect(app(ExecutionRepository::class)->getStatus('exec-pbf-boom'))->toBe('failed')
        ->and(app(ExecutionRepository::class)->get('exec-pbf-boom')?->error)->toContain('branch boom');
    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
});

it('fails the execution when a branch succeeds and the batch cannot record it', function () {
    Event::fake([GraphExecutionFailed::class]);

    pbfFanOut('exec-pbf-ok', ['ok']);

    expect(app(ExecutionRepository::class)->getStatus('exec-pbf-ok'))->toBe('failed')
        ->and(app(ExecutionRepository::class)->get('exec-pbf-ok')?->error)->toContain('database is locked');
    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
});

it('fails the execution once when the batch records the failure and its catch callback runs', function () {
    PBFLockedBatchRepository::$locked = false;
    Event::fake([GraphExecutionFailed::class]);

    pbfFanOut('exec-pbf-once', ['boom']);

    expect(app(ExecutionRepository::class)->getStatus('exec-pbf-once'))->toBe('failed')
        ->and(app(ExecutionRepository::class)->get('exec-pbf-once')?->error)->toContain('branch boom');
    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
});
