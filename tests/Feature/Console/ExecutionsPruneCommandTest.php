<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\NodeMiddlewarePipeline;
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
use Shahzad\AgenticGraph\Storage\DatabaseStorage;
use Shahzad\AgenticGraph\Storage\FileStorage;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamResumption;

/**
 * agenticgraph:executions:prune is the only way to delete runs. It removes completed and failed runs older than
 * execution.retention_days with everything stored for them, and is off by default.
 */
class PruneNode extends PrismNode
{
    public static int $runs = 0;

    public function execute(StateContract $state): StateContract
    {
        self::$runs++;

        return $state->set('done', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return $state->has('ask') && ! $state->has('approved');
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

function pruneCheckpoint(string $executionId, int $sequence): void
{
    $checkpoint = new Checkpoint;
    $checkpoint->id = "ckpt-{$executionId}-{$sequence}";
    $checkpoint->graphName = 'prune';
    $checkpoint->executionId = $executionId;
    $checkpoint->state = new State(['n' => $sequence]);
    $checkpoint->currentNode = 'work';
    $checkpoint->metadata = ['checkpoint_sequence' => $sequence];
    $checkpoint->createdAt = new DateTimeImmutable;
    app(StorageInterface::class)->save($checkpoint);
}

function pruneEventsQuery(): Builder
{
    return DB::connection(config('agenticgraph.streaming.store.database.connection'))
        ->table(config('agenticgraph.streaming.store.database.table', 'stream_events'));
}

/**
 * A run with a row in every table: two stream events, an interrupt, a batch result and two checkpoints. $age is the
 * age in days of completed_at (completed runs) and updated_at.
 */
function pruneRun(string $id, string $status, int $age): void
{
    $at = now()->subDays($age);
    DB::table('agenticgraph_executions')->insert([
        'id' => $id, 'graph_name' => 'prune', 'status' => $status,
        'completed_at' => $status === 'completed' ? $at : null,
        'created_at' => $at, 'updated_at' => $at,
    ]);
    foreach ([1, 2] as $seq) {
        pruneEventsQuery()->insert([
            'stream_id' => "stream-{$id}", 'execution_id' => $id, 'seq' => $seq, 'type' => 'node_start',
            'payload' => '{}', 'occurred_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }
    DB::table('agenticgraph_interrupts')->insert([
        'execution_id' => $id, 'checkpoint_id' => "ckpt-{$id}-1", 'graph_name' => 'prune', 'node_name' => 'work',
        'reason' => 'r', 'status' => 'resolved', 'created_at' => $at, 'updated_at' => $at,
    ]);
    app(ExecutionRepository::class)->putBatchResult($id, 'work', ['n' => 1]);
    pruneCheckpoint($id, 1);
    pruneCheckpoint($id, 2);
}

/** @return array<string, int> */
function pruneFootprint(string $id): array
{
    return [
        'row' => DB::table('agenticgraph_executions')->where('id', $id)->count(),
        'events' => pruneEventsQuery()->where('execution_id', $id)->count(),
        'interrupts' => DB::table('agenticgraph_interrupts')->where('execution_id', $id)->count(),
        'batch_results' => DB::table('agenticgraph_batch_results')->where('execution_id', $id)->count(),
        'checkpoints' => count(app(StorageInterface::class)->listForExecution($id)),
    ];
}

const PRUNE_FULL = ['row' => 1, 'events' => 2, 'interrupts' => 1, 'batch_results' => 1, 'checkpoints' => 2];

const PRUNE_NONE = ['row' => 0, 'events' => 0, 'interrupts' => 0, 'batch_results' => 0, 'checkpoints' => 0];

function pruneFileStorage(string $dir): void
{
    config()->set('agenticgraph.storage', 'file');
    app()->bind(FileStorage::class, fn () => new FileStorage($dir));
}

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON');
    expect((int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);
    config()->set('agenticgraph.streaming.store.driver', 'database');
    app()->forgetInstance(StreamStoreInterface::class);
    config()->set('agenticgraph.execution.retention_days', 30);
    $this->dir = sys_get_temp_dir().'/ag-prune-'.bin2hex(random_bytes(6));
    PruneNode::$runs = 0;
});

afterEach(function () {
    foreach (glob($this->dir.'/{,.}*.json', GLOB_BRACE) ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->dir);
});

it('is off by default and deletes nothing', function () {
    $shipped = require __DIR__.'/../../../config/agenticgraph.php';
    expect($shipped['execution'])->toHaveKey('retention_days')
        ->and($shipped['execution']['retention_days'])->toBeNull();
    pruneRun('old-completed', 'completed', 900);

    foreach ([null, '', 0, '0'] as $off) {
        config()->set('agenticgraph.execution.retention_days', $off);
        $this->artisan('agenticgraph:executions:prune')->expectsOutputToContain('Retention is off')->assertSuccessful();
    }

    expect(pruneFootprint('old-completed'))->toBe(PRUNE_FULL);
});

it('deletes a value it cannot read nothing, and fails', function (mixed $configured, ?string $option) {
    pruneRun('old-completed', 'completed', 900);
    config()->set('agenticgraph.execution.retention_days', $configured);

    $this->artisan('agenticgraph:executions:prune'.($option === null ? '' : " --days={$option}"))->assertFailed();

    expect(pruneFootprint('old-completed'))->toBe(PRUNE_FULL);
})->with([
    'negative config' => [-1, null],
    'text config' => ['thirty', null],
    'fraction config' => ['1.5', null],
    'zero option' => [30, '0'],
    'negative option' => [30, '-5'],
    'text option' => [30, 'all'],
    'empty option' => [30, ''],
]);

it('prunes only completed and failed runs', function (string $status, bool $pruned) {
    pruneRun('subject', $status, 90);

    $this->artisan('agenticgraph:executions:prune')->assertSuccessful();

    expect(pruneFootprint('subject'))->toBe($pruned ? PRUNE_NONE : PRUNE_FULL);
})->with([
    ['completed', true],
    ['failed', true],
    ['queued', false],
    ['running', false],
    ['interrupted', false],
]);

it('ages a run by completed_at, else updated_at', function (string $status, ?int $completedAge, int $updatedAge, bool $pruned) {
    pruneRun('subject', $status, 1);
    DB::table('agenticgraph_executions')->where('id', 'subject')->update([
        'completed_at' => $completedAge === null ? null : now()->subDays($completedAge),
        'updated_at' => now()->subDays($updatedAge),
        'created_at' => now()->subDays(400),
    ]);

    $this->artisan('agenticgraph:executions:prune')->assertSuccessful();

    expect(pruneFootprint('subject')['row'])->toBe($pruned ? 0 : 1);
})->with([
    'completed, old' => ['completed', 31, 31, true],
    'completed, newer' => ['completed', 29, 29, false],
    'completed long ago, touched since' => ['completed', 31, 1, true],
    'completed recently, old updated_at' => ['completed', 1, 31, false],
    'failed with no completed_at, old' => ['failed', null, 31, true],
    'failed with no completed_at, newer' => ['failed', null, 29, false],
]);

it('removes every row of a pruned run and leaves the others', function () {
    pruneRun('old-completed', 'completed', 90);
    pruneRun('old-failed', 'failed', 90);
    pruneRun('new-completed', 'completed', 2);
    pruneRun('new-failed', 'failed', 2);
    pruneRun('old-running', 'running', 90);
    expect(pruneFootprint('old-completed'))->toBe(PRUNE_FULL);

    $this->artisan('agenticgraph:executions:prune')->expectsOutputToContain('Pruned 2 execution(s).')->assertSuccessful();

    expect(pruneFootprint('old-completed'))->toBe(PRUNE_NONE)
        ->and(pruneFootprint('old-failed'))->toBe(PRUNE_NONE)
        ->and(pruneFootprint('new-completed'))->toBe(PRUNE_FULL)
        ->and(pruneFootprint('new-failed'))->toBe(PRUNE_FULL)
        ->and(pruneFootprint('old-running'))->toBe(PRUNE_FULL);
});

it('takes --days over the config, also when retention is off', function (?int $configured) {
    config()->set('agenticgraph.execution.retention_days', $configured);
    pruneRun('ten-days', 'completed', 10);
    pruneRun('two-days', 'completed', 2);

    $this->artisan('agenticgraph:executions:prune --days=7')->assertSuccessful();

    expect(pruneFootprint('ten-days'))->toBe(PRUNE_NONE)
        ->and(pruneFootprint('two-days'))->toBe(PRUNE_FULL);
})->with(['config 30' => [30], 'off' => [null]]);

it('reads the days from the config as a numeric string', function () {
    config()->set('agenticgraph.execution.retention_days', '30');
    pruneRun('old', 'completed', 31);

    $this->artisan('agenticgraph:executions:prune')->assertSuccessful();

    expect(pruneFootprint('old'))->toBe(PRUNE_NONE);
});

it('takes at most --limit runs, oldest first, and a later pass takes the rest', function () {
    pruneRun('b-newest', 'completed', 40);
    pruneRun('c-oldest', 'failed', 90);
    pruneRun('a-middle', 'completed', 60);

    $this->artisan('agenticgraph:executions:prune --limit=2')->expectsOutputToContain('Pruned 2 execution(s).')->assertSuccessful();

    expect(pruneFootprint('c-oldest'))->toBe(PRUNE_NONE)
        ->and(pruneFootprint('a-middle'))->toBe(PRUNE_NONE)
        ->and(pruneFootprint('b-newest'))->toBe(PRUNE_FULL);

    $this->artisan('agenticgraph:executions:prune --limit=2')->expectsOutputToContain('Pruned 1 execution(s).')->assertSuccessful();

    expect(pruneFootprint('b-newest'))->toBe(PRUNE_NONE);
});

// Recovery can reopen a failed run between the candidate query and the delete.
it('keeps a run reopened after it was selected', function () {
    pruneRun('reopened', 'failed', 90);
    $reopened = false;
    DB::listen(function ($query) use (&$reopened): void {
        if (! $reopened && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'agenticgraph_executions')) {
            $reopened = true;
            app(ExecutionRepository::class)->reopen('reopened');
        }
    });

    $this->artisan('agenticgraph:executions:prune')->expectsOutputToContain('Pruned 0 execution(s).')->assertSuccessful();

    expect($reopened)->toBeTrue()
        ->and(pruneFootprint('reopened'))->toBe(PRUNE_FULL);
});

it('deletes checkpoints kept outside the database', function () {
    pruneFileStorage($this->dir);
    pruneRun('old', 'completed', 90);
    pruneRun('new', 'completed', 2);
    expect(glob($this->dir.'/ckpt-old-*.json'))->toHaveCount(2);

    $this->artisan('agenticgraph:executions:prune')->assertSuccessful();

    expect(glob($this->dir.'/ckpt-old-*.json'))->toBe([])
        ->and(pruneFootprint('old'))->toBe(PRUNE_NONE)
        ->and(pruneFootprint('new'))->toBe(PRUNE_FULL);
});

it('deletes stream events from the configured connection and table, whatever the store driver', function (string $driver) {
    config()->set('database.connections.streams', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('agenticgraph.streaming.store.database.connection', 'streams');
    config()->set('agenticgraph.streaming.store.database.table', 'my_stream_events');
    config()->set('agenticgraph.streaming.store.driver', $driver);
    Schema::connection('streams')->create('my_stream_events', function (Blueprint $table) {
        $table->id();
        foreach (['stream_id', 'execution_id', 'type', 'payload'] as $column) {
            $table->string($column);
        }
        $table->unsignedInteger('seq');
        $table->timestamp('occurred_at');
        $table->timestamps();
    });
    pruneRun('old', 'completed', 90);
    pruneRun('new', 'completed', 2);

    $this->artisan('agenticgraph:executions:prune')->assertSuccessful();

    expect(pruneFootprint('old'))->toBe(PRUNE_NONE)
        ->and(pruneFootprint('new'))->toBe(PRUNE_FULL);
})->with(['database', 'redis']);

it('prunes an app that has no stream events table', function () {
    config()->set('agenticgraph.streaming.store.driver', 'redis');
    pruneRun('old', 'completed', 90);
    Schema::drop('stream_events');

    $this->artisan('agenticgraph:executions:prune')->assertSuccessful();

    expect(DB::table('agenticgraph_executions')->count())->toBe(0);
});

/** A foreign key without cascade: the database refuses to delete these rows. */
function pruneHold(string ...$ids): void
{
    Schema::create('prune_hold', function (Blueprint $table) {
        $table->string('execution_id');
        $table->foreign('execution_id')->references('id')->on('agenticgraph_executions');
    });
    foreach ($ids as $id) {
        DB::table('prune_hold')->insert(['execution_id' => $id]);
    }
}

/** @return ArrayObject<int, array<string, mixed>> the context of each prune failure logged from now on */
function pruneFailureLog(): ArrayObject
{
    $logged = new ArrayObject;
    Log::listen(function (MessageLogged $message) use ($logged): void {
        if ($message->level === 'error' && str_contains($message->message, 'execution prune failed')) {
            $logged[] = $message->context;
        }
    });

    return $logged;
}

it('walks past runs it cannot delete, so they do not use up --limit', function () {
    foreach ([1, 2, 3] as $n) {
        pruneRun("stuck-{$n}", 'completed', 100 - $n);
    }
    foreach ([1, 2, 3, 4, 5] as $n) {
        pruneRun("free-{$n}", 'completed', 60 - $n);
    }
    pruneHold('stuck-1', 'stuck-2', 'stuck-3');
    $logged = pruneFailureLog();

    $this->artisan('agenticgraph:executions:prune --limit=3')
        ->expectsOutputToContain('Pruned 3 execution(s).')
        ->expectsOutputToContain('3 execution(s) could not be pruned')
        ->assertFailed();

    expect(array_column($logged->getArrayCopy(), 'execution_id'))->toBe(['stuck-1', 'stuck-2', 'stuck-3'])
        ->and($logged[0]['message'])->toContain('FOREIGN KEY constraint failed');
    foreach (['free-1', 'free-2', 'free-3'] as $id) {
        expect(pruneFootprint($id))->toBe(PRUNE_NONE);
    }
    // The failed delete is rolled back as one, and --limit still caps the deletes.
    foreach (['stuck-1', 'stuck-2', 'stuck-3', 'free-4', 'free-5'] as $id) {
        expect(pruneFootprint($id))->toBe(PRUNE_FULL);
    }
});

it('ends the pass, with each failure logged once, when runs of the same age cannot be deleted', function () {
    $this->freezeTime();
    foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
        pruneRun($id, 'completed', 90);
    }
    pruneHold('a', 'b', 'd');
    $logged = pruneFailureLog();

    $this->artisan('agenticgraph:executions:prune --limit=1')->expectsOutputToContain('Pruned 1 execution(s).')->assertFailed();

    expect(array_column($logged->getArrayCopy(), 'execution_id'))->toBe(['a', 'b'])
        ->and(pruneFootprint('c'))->toBe(PRUNE_NONE)
        ->and(pruneFootprint('e'))->toBe(PRUNE_FULL);

    $logged->exchangeArray([]);
    $this->artisan('agenticgraph:executions:prune --limit=500')->expectsOutputToContain('Pruned 1 execution(s).')->assertFailed();

    expect(array_column($logged->getArrayCopy(), 'execution_id'))->toBe(['a', 'b', 'd']);
});

it('skips a table the app does not have', function (string $table) {
    pruneRun('old', 'completed', 90);
    Schema::drop($table);

    $this->artisan('agenticgraph:executions:prune')->expectsOutputToContain('Pruned 1 execution(s).')->assertSuccessful();

    expect(DB::table('agenticgraph_executions')->count())->toBe(0);
})->with(['agenticgraph_batch_results', 'agenticgraph_interrupts', 'agenticgraph_checkpoints']);

it('keeps the run row after a partial failure, goes on to the next run, and a rerun finishes', function () {
    pruneRun('broken', 'completed', 90);
    pruneRun('healthy', 'completed', 60);
    $storage = new class(app(DatabaseStorage::class)) extends DatabaseStorage
    {
        public bool $failing = true;

        public function __construct(DatabaseStorage $unused)
        {
            parent::__construct();
        }

        public function delete(string $id): bool
        {
            if ($this->failing && str_contains($id, 'broken')) {
                throw new RuntimeException('storage down');
            }

            return parent::delete($id);
        }
    };
    app()->instance(StorageInterface::class, $storage);
    app()->bind(DatabaseStorage::class, fn () => $storage);

    $this->artisan('agenticgraph:executions:prune')
        ->expectsOutputToContain('Pruned 1 execution(s).')
        ->expectsOutputToContain('1 execution(s) could not be pruned')
        ->assertFailed();

    expect(pruneFootprint('broken')['row'])->toBe(1)
        ->and(pruneFootprint('broken')['checkpoints'])->toBe(2)
        ->and(pruneFootprint('healthy'))->toBe(PRUNE_NONE);

    $storage->failing = false;
    $this->artisan('agenticgraph:executions:prune')->expectsOutputToContain('Pruned 1 execution(s).')->assertSuccessful();

    expect(pruneFootprint('broken'))->toBe(PRUNE_NONE);
});

describe('a late queued job for a pruned run', function () {
    beforeEach(function () {
        pruneFileStorage($this->dir); // File checkpoints do not cascade: nothing hides a write.
        config()->set('agenticgraph.checkpoint.enabled', true);
        config()->set('agenticgraph.interrupts.persistence_enabled', true);
        config()->set('agenticgraph.execution.queue_connection', 'sync');
        AgenticGraph::register('prune', GraphBuilder::create('prune')
            ->addNode('work', PruneNode::class)
            ->setEntryPoint('work')
            ->addExitPoint('work')
            ->build());
        Bus::fake();
    });

    /** Queues a run, runs it, ages it past the limit and prunes it. Returns its first job. */
    function pruneLateJob(StreamingMode $streaming, State $state): ExecuteGraphJob
    {
        $handle = AgenticGraph::execute('prune', $state, new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: $streaming, interrupts: true));
        $job = Bus::dispatched(ExecuteGraphJob::class)->first();
        app()->call([$job, 'handle']);
        expect(pruneFootprint($handle->id())['row'])->toBe(1);

        return $job;
    }

    function pruneNow(string $id): void
    {
        DB::table('agenticgraph_executions')->where('id', $id)->update(['status' => 'completed', 'completed_at' => now()->subDays(90), 'updated_at' => now()->subDays(90)]);
        expect(Artisan::call('agenticgraph:executions:prune'))->toBe(0)
            ->and(pruneFootprint($id))->toBe(PRUNE_NONE);
        PruneNode::$runs = 0;
    }

    it('creates nothing: graph, continue, recovery, branch and failed()', function (StreamingMode $streaming) {
        $job = pruneLateJob($streaming, new State);
        $id = $job->executionId;
        pruneNow($id);

        app()->call([$job, 'handle']);
        app()->call([new ContinueExecutionJob('prune', $id, (new State)->toArray(), 'work', streamId: 'late', streamSeq: 0), 'handle']);
        (new ExecuteNodeJob($id, 'work', (new State)->toArray(), 'prune'))->handle(app(GraphRegistry::class), app(NodeMiddlewarePipeline::class));
        $job->failed(new RuntimeException('late'));

        expect(PruneNode::$runs)->toBe(0)
            ->and(pruneFootprint($id))->toBe(PRUNE_NONE)
            ->and(glob($this->dir.'/*.json'))->toBe([]);
    })->with(['no stream' => [StreamingMode::NONE], 'sse' => [StreamingMode::SSE]]);

    it('creates nothing: resume', function (StreamingMode $streaming) {
        $job = pruneLateJob($streaming, new State(['ask' => true]));
        $id = $job->executionId;
        AgenticGraph::getExecution($id)->resume(['approved' => true]);
        $resume = Bus::dispatched(ResumeExecutionJob::class)->first();
        pruneNow($id);

        $resume->handle(app(InterruptServiceInterface::class), app(StreamResumption::class));
        $resume->failed(new RuntimeException('late'));

        expect(PruneNode::$runs)->toBe(0)
            ->and(pruneFootprint($id))->toBe(PRUNE_NONE);
    })->with(['no stream' => [StreamingMode::NONE], 'sse' => [StreamingMode::SSE]]);
});

// Review decision 3: no second way to delete runs.
it('leaves no other way to delete runs on the repository', function () {
    expect(method_exists(ExecutionRepository::class, 'cleanup'))->toBeFalse();
});
