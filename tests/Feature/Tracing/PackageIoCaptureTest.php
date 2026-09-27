<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shahzad\AgenticGraph\AgenticGraphServiceProvider;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\ExecutionContextManager;
use Shahzad\AgenticGraph\Execution\ExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptManager;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;

// With db capture on for the whole run, package bookkeeping (execution rows, checkpoints,
// interrupts, batch dispatch) must not become db spans or open a headerless segment.

class IoSentExporter extends PulseExporter
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function isEnabled(): bool
    {
        return true;
    }

    protected function send(array $payload): void
    {
        $this->sent[] = $payload;
    }
}

class IoUserNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        DB::select('select 42 as user_query');

        return $state->set('u', true);
    }
}

class IoBoomNode extends AbstractNode
{
    public function getRetryAttempts(): int
    {
        return 0;
    }

    public function execute(StateContract $state): StateContract
    {
        DB::select('select 42 as user_query');
        throw new RuntimeException('io boom');
    }
}

class IoApprovalNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('approved_run', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->get('approved', false);
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

function ioForgetRun(): void
{
    foreach ([TraceContext::class, ExecutionClock::class, ExecutionContext::class, Graph::class, ExecutionContextManager::class, InterruptManager::class, ParallelExecutionCoordinator::class, ExecutionCoordinator::class, GraphExecutor::class] as $binding) {
        app()->forgetInstance($binding);
    }
}

function ioExporter(): IoSentExporter
{
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.checkpoint.enabled' => true,
        'agenticgraph.tracing.capture.db' => true,
        'agenticgraph.tracing.capture.db_sql' => 'full',
        'agenticgraph.execution.queue_connection' => 'sync',
        'queue.default' => 'sync',
    ]);
    $exporter = new IoSentExporter;
    app()->instance(TracerExporter::class, $exporter);
    app()->instance(PulseExporter::class, $exporter);
    ioForgetRun();
    $provider = app()->getProvider(AgenticGraphServiceProvider::class);
    (fn () => $this->registerTracing())->call($provider);

    return $exporter;
}

/**
 * @param  list<array<string, mixed>>  $payloads
 */
function ioAssertCleanTrace(array $payloads, bool $singleSegment): void
{
    expect($payloads)->not->toBeEmpty();

    $initial = array_values(array_filter($payloads, fn (array $p): bool => $p['segment']['kind'] === 'initial'));
    expect($initial)->toHaveCount(1);
    expect($initial[0]['trace'])->toHaveKeys(['graph_name', 'started_at', 'initial_state_keys']);
    if ($singleSegment) {
        expect($payloads)->toHaveCount(1);
        expect($initial[0]['trace'])->toHaveKeys(['duration_ms', 'active_ms']);
    }

    $finals = array_keys(array_filter($payloads, fn (array $p): bool => $p['segment']['final']));
    expect($finals)->toBe([count($payloads) - 1]);

    $sql = [];
    foreach ($payloads as $payload) {
        foreach ($payload['spans'] as $span) {
            if ($span['span_type'] === 'db') {
                $sql[] = $span['attributes']['db.query.text'] ?? '';
            }
        }
    }
    expect($sql)->not->toBeEmpty();
    expect(array_unique($sql))->toBe(['select 42 as user_query']);
}

it('sync run: one initial segment with the full header and only user db spans', function () {
    $exporter = ioExporter();
    $graph = GraphBuilder::create('io-sync')->addNode('a', IoUserNode::class)->addNode('b', IoUserNode::class)
        ->addEdge('a', 'b')->setEntryPoint('a')->addExitPoint('b')->build();

    app(GraphExecutor::class)->execute($graph, new State(['q' => 1]));

    ioAssertCleanTrace($exporter->sent, singleSegment: true);
    expect($exporter->sent[0]['trace']['status'])->toBe('completed');
});

it('failing run: one initial segment with the full header and only user db spans', function () {
    $exporter = ioExporter();
    $graph = GraphBuilder::create('io-fail')->addNode('a', IoUserNode::class)->addNode('b', IoBoomNode::class)
        ->addEdge('a', 'b')->setEntryPoint('a')->addExitPoint('b')->build();

    expect(fn () => app(GraphExecutor::class)->execute($graph, new State(['q' => 1])))->toThrow(RuntimeException::class);

    ioAssertCleanTrace($exporter->sent, singleSegment: true);
    expect($exporter->sent[0]['trace']['status'])->toBe('failed');
});

it('interrupt and resume: initial then resumed, and only user db spans', function () {
    $exporter = ioExporter();
    $graph = GraphBuilder::create('io-int')->addNode('a', IoUserNode::class)->addNode('approve', IoApprovalNode::class)->addNode('b', IoUserNode::class)
        ->addEdge('a', 'approve')->addEdge('approve', 'b')->setEntryPoint('a')->addExitPoint('b')->build();
    app(GraphRegistry::class)->register('io-int', $graph);

    $state = app(GraphExecutor::class)->execute($graph, new State(['q' => 1]));
    ioForgetRun();
    app(GraphExecutor::class)->resumeWithInput($state->getInterruptCheckpointId(), ['ok' => true]);

    ioAssertCleanTrace($exporter->sent, singleSegment: false);
    expect(array_map(fn (array $p): string => $p['segment']['kind'], $exporter->sent))->toBe(['initial', 'resumed']);
});

it('batch fan-out with one worker: one initial segment and only user db spans', function () {
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
    Schema::create('jobs', function (Blueprint $t) {
        $t->id();
        $t->string('queue')->index();
        $t->longText('payload');
        $t->unsignedTinyInteger('attempts');
        $t->unsignedInteger('reserved_at')->nullable();
        $t->unsignedInteger('available_at');
        $t->unsignedInteger('created_at');
    });
    Schema::create('failed_jobs', function (Blueprint $t) {
        $t->id();
        $t->string('uuid')->unique();
        $t->text('connection');
        $t->text('queue');
        $t->longText('payload');
        $t->longText('exception');
        $t->timestamp('failed_at')->useCurrent();
    });
    $exporter = ioExporter();
    config([
        'agenticgraph.execution.queue_connection' => 'database',
        'queue.batching.database' => 'testing',
        'queue.connections.database' => ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
    ]);
    $router = new class implements RouterInterface
    {
        public function route(StateContract $state): string|array
        {
            return ['x', 'y'];
        }

        public function getAvailableRoutes(): array
        {
            return ['x', 'y'];
        }

        public function getDefaultRoute(): ?string
        {
            return null;
        }

        public function validateRoute(string $route): bool
        {
            return true;
        }
    };
    $graph = GraphBuilder::create('io-batch')->addNode('s', IoUserNode::class)->addNode('x', IoUserNode::class)->addNode('y', IoUserNode::class)->addNode('end', IoUserNode::class)
        ->addConditionalEdge('s', $router)->addJoin(['x', 'y'], 'end')->addEdge('x', 'end')->addEdge('y', 'end')->setEntryPoint('s')->addExitPoint('end')->build();
    app(GraphRegistry::class)->register('io-batch', $graph);

    app(GraphExecutor::class)->execute($graph, new State(['q' => 1]), useBatch: true);
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'agenticgraph', '--stop-when-empty' => true, '--memory' => 4096]);

    expect(DB::table('failed_jobs')->count())->toBe(0);
    ioAssertCleanTrace($exporter->sent, singleSegment: false);
    expect(end($exporter->sent)['trace']['status'])->toBe('completed');
});

it('a span emitted before startTrace still yields an initial segment with the full header', function () {
    $exporter = new IoSentExporter;
    $startedAt = new DateTimeImmutable('2026-09-28T10:00:00.000000Z');

    $exporter->addSpan(['trace_id' => 'tr-early', 'span_id' => 'sp-user', 'span_type' => 'custom', 'node_name' => 'user.before', 'started_at' => '2026-09-28T09:59:59.500000Z']);
    $exporter->startTrace('tr-early', 'io-early', ['q'], $startedAt);
    $exporter->completeTrace('tr-early', ['q'], 1000.0, null, $startedAt->modify('+1 second'));

    expect($exporter->sent)->toHaveCount(1);
    $payload = $exporter->sent[0];
    expect($payload['segment']['kind'])->toBe('initial');
    expect($payload['segment']['final'])->toBeTrue();
    expect($payload['trace'])->toMatchArray(['graph_name' => 'io-early', 'initial_state_keys' => ['q'], 'status' => 'completed']);
    expect($payload['trace'])->toHaveKeys(['started_at', 'duration_ms', 'active_ms']);
    expect(array_column($payload['spans'], 'span_id'))->toContain('sp-user');
});

it('guard: a user query inside a node is still captured as a db span', function () {
    $exporter = ioExporter();
    $graph = GraphBuilder::create('io-user')->addNode('a', IoUserNode::class)->setEntryPoint('a')->addExitPoint('a')->build();

    app(GraphExecutor::class)->execute($graph, new State(['q' => 1]));

    $db = array_values(array_filter($exporter->sent[0]['spans'], fn (array $s): bool => $s['span_type'] === 'db'));
    expect($db)->toHaveCount(1);
    expect($db[0]['attributes']['db.query.text'])->toBe('select 42 as user_query');
});
