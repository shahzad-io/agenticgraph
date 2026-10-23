<?php

declare(strict_types=1);

use Illuminate\Bus\Batch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\InterruptExpired;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\ExecutionContextManager;
use Shahzad\AgenticGraph\Execution\ExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptManager;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumption;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceSegmentMerger;
use Shahzad\AgenticGraph\Tracing\TraceTime;

class RootLifecycleExporter extends PulseExporter
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    protected function send(array $payload): void
    {
        $this->sent[] = $payload;
    }
}

class RlStart extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('started', true);
    }
}

class RlBranchA extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        rlEmitLlmCall('a');

        return $state->set('a', 1);
    }
}

class RlBranchB extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        rlEmitLlmCall('b');

        return $state->set('b', 2);
    }
}

class RlEnd extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('ended', true);
    }
}

class RlBoom extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        throw new RuntimeException('rl boom');
    }

    public function shouldRetry(Throwable $exception): bool
    {
        return false;
    }
}

class RlApproval extends PrismNode
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

function rlEmitLlmCall(string $node): void
{
    $start = microtime(true);
    usleep(2000);
    event(TokensUsed::fromLlmCall($node, $start, (microtime(true) - $start) * 1000, 1, 1, 'text', 'test:model'));
}

function rlExporter(): RootLifecycleExporter
{
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.endpoint' => 'https://api.example.com',
        'agenticgraph.tracing.api_key' => 'test-key',
        'agenticgraph.checkpoint.enabled' => true,
    ]);
    // Console context: flush sends synchronously.
    app()->forgetInstance('request');

    $exporter = new RootLifecycleExporter;
    app()->instance(TracerExporter::class, $exporter);
    app()->instance(PulseExporter::class, $exporter);
    rlForgetExecutionServices();

    return $exporter;
}

function rlForgetExecutionServices(): void
{
    foreach ([
        ExecutionContextManager::class,
        InterruptManager::class,
        ParallelExecutionCoordinator::class,
        ExecutionCoordinator::class,
        GraphExecutor::class,
    ] as $service) {
        app()->forgetInstance($service);
    }
}

/**
 * Simulates a fresh PHP process: open segments and per-run bindings are gone, the cache
 * (checkpoints) survives.
 */
function rlNewProcess(RootLifecycleExporter $exporter): void
{
    $exporter->reset();
    foreach ([TraceContext::class, ExecutionClock::class, ExecutionContext::class, Graph::class] as $binding) {
        app()->forgetInstance($binding);
    }
    rlForgetExecutionServices();
}

function rlRouter(): RouterInterface
{
    return new class implements RouterInterface
    {
        public function route(StateContract $state): string|array
        {
            return ['a', 'b'];
        }

        public function getAvailableRoutes(): array
        {
            return ['a', 'b'];
        }

        public function getDefaultRoute(): ?string
        {
            return null;
        }

        public function validateRoute(string $route): bool
        {
            return in_array($route, ['a', 'b'], true);
        }
    };
}

function rlFanOutGraph(string $name): Graph
{
    $graph = GraphBuilder::create($name)
        ->addNode('start', RlStart::class)
        ->addNode('a', RlBranchA::class)
        ->addNode('b', RlBranchB::class)
        ->addNode('end', RlEnd::class)
        ->addConditionalEdge('start', rlRouter())
        ->addJoin(['a', 'b'], 'end')
        ->addEdge('a', 'end')
        ->addEdge('b', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    app(GraphRegistry::class)->register($name, $graph);

    return $graph;
}

/**
 * @param  array<int, array{0: string, 1: class-string}>  $nodes
 */
function rlLinearGraph(string $name, array $nodes): Graph
{
    $builder = GraphBuilder::create($name);
    foreach ($nodes as [$node, $class]) {
        $builder->addNode($node, $class);
    }
    for ($i = 1; $i < count($nodes); $i++) {
        $builder->addEdge($nodes[$i - 1][0], $nodes[$i][0]);
    }
    $graph = $builder->setEntryPoint($nodes[0][0])->addExitPoint($nodes[count($nodes) - 1][0])->build();
    app(GraphRegistry::class)->register($name, $graph);

    return $graph;
}

/**
 * Every parent resolves inside the payload, exactly one span is a root, and it is the graph span.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed> the root span
 */
function assertTraceTree(array $payload): array
{
    $spans = $payload['spans'];
    $ids = array_column($spans, 'id');
    $roots = array_values(array_filter($spans, fn (array $span): bool => $span['parent_id'] === null));

    expect($ids)->toBe(array_values(array_unique($ids)))
        ->and($roots)->toHaveCount(1)
        ->and($roots[0]['span_type'])->toBe('graph');

    foreach ($spans as $span) {
        if ($span['parent_id'] !== null) {
            expect($ids)->toContain($span['parent_id']);
        }
    }

    return $roots[0];
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, array<string, mixed>>
 */
function rlNodeSpans(array $payload): array
{
    $nodes = [];
    foreach ($payload['spans'] as $span) {
        if ($span['span_type'] === 'node') {
            $nodes[$span['node_name']] = $span;
        }
    }

    return $nodes;
}

function rlMicros(string $at): int
{
    return (int) (new DateTimeImmutable($at))->format('Uu');
}

afterEach(function () {
    Cache::flush();
});

it('sync path emits a single resolving root', function () {
    $exporter = rlExporter();
    $graph = rlLinearGraph('rl-sync', [['start', RlStart::class], ['end', RlEnd::class]]);

    app(GraphExecutor::class)->execute($graph, new State([]));

    expect($exporter->sent)->toHaveCount(1);
    $root = assertTraceTree($exporter->sent[0]);
    foreach (rlNodeSpans($exporter->sent[0]) as $node) {
        expect($node['parent_id'])->toBe($root['id']);
    }
});

it('sync fan-out branch spans parent the graph span and do not overlap', function () {
    $exporter = rlExporter();
    $graph = rlFanOutGraph('rl-sync-fanout');

    app(GraphExecutor::class)->execute($graph, new State([]));

    $payload = $exporter->sent[0];
    $root = assertTraceTree($payload);
    $nodes = rlNodeSpans($payload);
    $llmByParent = [];
    foreach ($payload['spans'] as $span) {
        if ($span['span_type'] === 'llm') {
            $llmByParent[$span['parent_id']] = $span;
        }
    }

    foreach (['start', 'a', 'b', 'end'] as $name) {
        expect($nodes[$name]['parent_id'])->toBe($root['id']);
    }
    foreach (['a', 'b'] as $name) {
        $llm = $llmByParent[$nodes[$name]['id']];
        expect(rlMicros($nodes[$name]['started_at']))->toBeLessThanOrEqual(rlMicros($llm['started_at']))
            ->and(rlMicros($nodes[$name]['ended_at']))->toBeGreaterThanOrEqual(rlMicros($llm['ended_at']));
    }
    expect(rlMicros($nodes['b']['started_at']))->toBeGreaterThanOrEqual(rlMicros($nodes['a']['ended_at']));
});

it('queue fan-out on one worker keeps one root across ExecuteNodeJob and ContinueExecutionJob', function () {
    $exporter = rlExporter();
    $fake = Bus::fake();
    rlForgetExecutionServices();
    rlFanOutGraph('rl-queue-fanout');
    $executionId = 'exec_rl_queue_'.bin2hex(random_bytes(4));
    app(ExecutionRepository::class)->queued($executionId, 'rl-queue-fanout', State::toStoragePayload(new State([])));

    $graphJob = unserialize(serialize(new ExecuteGraphJob('rl-queue-fanout', $executionId, [], null, 'span_rl_root')));
    app()->call([$graphJob, 'handle']);

    /** @var PendingBatch|null $pending */
    $pending = null;
    $fake->assertBatched(function (PendingBatch $batch) use (&$pending): bool {
        $pending = $batch;

        return true;
    });
    foreach ($pending->jobs as $job) {
        expect($job)->toBeInstanceOf(ExecuteNodeJob::class)
            ->and($job->parentSpanId)->toBe('span_rl_root');
        app()->call([unserialize(serialize($job)), 'handle']);
    }

    $then = unserialize(serialize($pending->thenCallbacks()[0]))->getClosure();
    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-rl';
    $then($batch);

    $continue = $fake->dispatched(ContinueExecutionJob::class)->first();
    expect($continue->rootSpanId)->toBe('span_rl_root');
    app()->call([unserialize(serialize($continue)), 'handle']);

    // Hand-off, each branch job, the join decision and the continuation each send a segment; merged, they form one tree.
    expect(array_map(fn (array $sent): string => $sent['segment']['kind'].($sent['segment']['final'] ? ':final' : ''), $exporter->sent))
        ->toBe(['initial', 'continued', 'continued', 'continued', 'continued:final']);
    $payload = (new TraceSegmentMerger)->merge($exporter->sent);
    $root = assertTraceTree($payload);
    expect($root['id'])->toBe('span_rl_root')
        ->and($root['duration_ms'])->toBeGreaterThan(0);
    foreach (rlNodeSpans($payload) as $node) {
        expect($node['parent_id'])->toBe('span_rl_root');
    }
});

it('ContinueExecutionJob carries rootSpanId through serialize and unserialize', function () {
    $job = unserialize(serialize(new ContinueExecutionJob('g', 'exec_rl', [], 'end', rootSpanId: 'span_rl_root')));

    expect($job->rootSpanId)->toBe('span_rl_root');
});

it('ContinueExecutionJob unserialized from a pre-change payload falls back without error', function () {
    $exporter = rlExporter();
    $graph = rlLinearGraph('rl-old-continue', [['start', RlStart::class], ['end', RlEnd::class]]);
    $executionId = 'exec_rl_old_'.bin2hex(random_bytes(4));
    app(ExecutionRepository::class)->queued($executionId, $graph->name, State::toStoragePayload(new State([])));

    // A job queued before rootSpanId existed: drop the property from the serialized form.
    $serialized = serialize(new ContinueExecutionJob($graph->name, $executionId, [], 'end'));
    $serialized = preg_replace('/s:10:"rootSpanId";N;/', '', $serialized);
    $serialized = preg_replace_callback('/^O:(\d+):"([^"]+)":(\d+):/', fn (array $m): string => "O:{$m[1]}:\"{$m[2]}\":".((int) $m[3] - 1).':', $serialized);
    $job = unserialize($serialized);

    expect(isset($job->rootSpanId))->toBeFalse();
    app()->call([$job, 'handle']);

    expect(app(ExecutionRepository::class)->getStatus($executionId))->toBe('completed');
});

it('interrupt flush contains the root graph span with status interrupted', function () {
    $exporter = rlExporter();
    $graph = rlLinearGraph('rl-interrupt', [['start', RlStart::class], ['approve', RlApproval::class], ['end', RlEnd::class]]);

    app(GraphExecutor::class)->execute($graph, new State([]));

    $payload = $exporter->sent[0];
    $root = assertTraceTree($payload);
    expect($payload['trace']['status'])->toBe('interrupted')
        ->and($root['status'])->toBe('interrupted')
        ->and($root['status_code'])->toBe('PAUSED')
        ->and($root['duration_ms'])->toBeGreaterThan(0)
        ->and(rlMicros($root['ended_at']))->toBeLessThanOrEqual(rlMicros($payload['trace']['interrupted_at']));
});

it('interrupt then resume in a new process reuses the interrupted root id', function () {
    $exporter = rlExporter();
    $graph = rlLinearGraph('rl-resume', [['start', RlStart::class], ['approve', RlApproval::class], ['end', RlEnd::class]]);

    $state = app(GraphExecutor::class)->execute($graph, new State([]));
    $interruptedRoot = assertTraceTree($exporter->sent[0]);

    rlNewProcess($exporter);
    app(GraphExecutor::class)->resumeWithInput($state->getInterruptCheckpointId(), ['ok' => true]);

    $payload = $exporter->sent[1];
    $root = assertTraceTree($payload);
    $graphSpans = array_filter($payload['spans'], fn (array $span): bool => $span['span_type'] === 'graph');
    $resume = array_values(array_filter($payload['spans'], fn (array $span): bool => $span['span_type'] === 'resume'))[0];

    expect($graphSpans)->toHaveCount(1)
        ->and($root['id'])->toBe($interruptedRoot['id'])
        ->and($root['status'])->toBe('success')
        ->and($resume['parent_id'])->toBe($root['id']);
});

it('resume from a checkpoint missing current_span_id falls back to root_span_id', function () {
    $exporter = rlExporter();
    $graph = rlLinearGraph('rl-old-checkpoint', [['start', RlStart::class], ['approve', RlApproval::class], ['end', RlEnd::class]]);

    $state = app(GraphExecutor::class)->execute($graph, new State([]));
    $checkpointId = $state->getInterruptCheckpointId();
    $storage = app(StorageInterface::class);
    $checkpoint = $storage->load($checkpointId);
    unset($checkpoint->metadata['trace_context']['current_span_id']);
    $storage->save($checkpoint);

    rlNewProcess($exporter);
    app(GraphExecutor::class)->resumeWithInput($checkpointId, ['ok' => true]);

    $payload = end($exporter->sent);
    $root = assertTraceTree($payload);
    $resume = array_values(array_filter($payload['spans'], fn (array $span): bool => $span['span_type'] === 'resume'))[0];
    expect($resume['parent_id'])->toBe($root['id']);
});

it('stream resume parents the resume span on the stored current_span_id', function () {
    $exporter = rlExporter();
    $graph = rlLinearGraph('rl-stream-resume', [['start', RlStart::class], ['approve', RlApproval::class], ['end', RlEnd::class]]);

    $state = app(GraphExecutor::class)->execute($graph, new State([]));
    $checkpointId = $state->getInterruptCheckpointId();
    $storedTrace = app(StorageInterface::class)->load($checkpointId)->metadata['trace_context'];

    rlNewProcess($exporter);
    app(StreamResumption::class)
        ->resumeStream($checkpointId, ['ok' => true], new StreamOptions(delivery: 'iterator'))
        ->getFinalState();

    $payload = end($exporter->sent);
    assertTraceTree($payload);
    $resume = array_values(array_filter($payload['spans'], fn (array $span): bool => $span['span_type'] === 'resume'))[0];
    expect($storedTrace)->not->toHaveKey('span_id')
        ->and($resume['parent_id'])->toBe($storedTrace['current_span_id']);
});

it('interrupt then resume reports duration_ms >= active_ms > 0', function () {
    $exporter = rlExporter();
    $graph = rlLinearGraph('rl-active', [['start', RlStart::class], ['approve', RlApproval::class], ['end', RlEnd::class]]);

    $state = app(GraphExecutor::class)->execute($graph, new State([]));
    $waitMs = 50.0;
    usleep((int) ($waitMs * 1000));

    rlNewProcess($exporter);
    app(GraphExecutor::class)->resumeWithInput($state->getInterruptCheckpointId(), ['ok' => true]);

    $trace = (new TraceSegmentMerger)->merge($exporter->sent)['trace'];
    expect($trace['active_ms'])->toBeGreaterThan(0)
        ->and($trace['duration_ms'])->toBeGreaterThanOrEqual($trace['active_ms'])
        ->and($trace['active_ms'])->toBeLessThanOrEqual($trace['duration_ms'] - $waitMs)
        ->and(rlMicros($trace['ended_at']))->toBeGreaterThan(rlMicros($trace['resumed_at']));
});

it('sync failure graph span uses the root id', function () {
    $exporter = rlExporter();
    $graph = rlLinearGraph('rl-fail', [['start', RlStart::class], ['boom', RlBoom::class]]);

    expect(fn () => app(GraphExecutor::class)->execute($graph, new State([])))->toThrow(RuntimeException::class);

    $payload = $exporter->sent[0];
    $root = assertTraceTree($payload);
    expect($root['status'])->toBe('failed')
        ->and(rlNodeSpans($payload)['start']['parent_id'])->toBe($root['id']);
});

it('failure after resume emits a graph span with the root id', function () {
    $exporter = rlExporter();
    $graph = rlLinearGraph('rl-resume-fail', [['start', RlStart::class], ['approve', RlApproval::class], ['boom', RlBoom::class]]);

    $state = app(GraphExecutor::class)->execute($graph, new State([]));
    $interruptedRoot = assertTraceTree($exporter->sent[0]);

    rlNewProcess($exporter);
    expect(fn () => app(GraphExecutor::class)->resumeWithInput($state->getInterruptCheckpointId(), ['ok' => true]))
        ->toThrow(RuntimeException::class);

    $root = assertTraceTree($exporter->sent[1]);
    expect($root['id'])->toBe($interruptedRoot['id'])
        ->and($root['status'])->toBe('failed');
});

it('GraphExecutionFailed without spanId still mints a span', function () {
    $exporter = rlExporter();
    $exporter->startTrace('exec_rl_legacy_fail', 'legacy');

    event(new GraphExecutionFailed('exec_rl_legacy_fail', 'legacy', new State([]), new RuntimeException('x'), 1.0));

    $root = assertTraceTree($exporter->sent[0]);
    expect($root['id'])->toStartWith('span_')
        ->and($root['status'])->toBe('failed');
});

it('ExecuteNodeJob queued before C1 still runs and nests under its old parent', function () {
    $exporter = rlExporter();
    rlFanOutGraph('rl-old-node-job');
    $exporter->startTrace('exec_rl_old_node', 'rl-old-node-job');

    // Same constructor shape as before C1; the old payload carried the orchestrator span id.
    $job = unserialize(serialize(new ExecuteNodeJob('exec_rl_old_node', 'a', [], 'rl-old-node-job', 'span_old_orchestrator')));
    app()->call([$job, 'handle']);

    $node = array_values(array_filter($exporter->sent[0]['spans'], fn (array $span): bool => $span['span_type'] === 'node'))[0];
    expect($node['parent_id'])->toBe('span_old_orchestrator')
        ->and(Cache::get('agenticgraph:batch:exec_rl_old_node:a'))->toBeArray();
});

/**
 * A worker process with its own exporter instance. Everything each worker sends lands in $sent.
 *
 * @param  array<int, array{worker: string, payload: array<string, mixed>}>  $sent
 */
function rlWorker(string $name, array &$sent): RootLifecycleExporter
{
    $exporter = new class($name, $sent) extends RootLifecycleExporter
    {
        /** @param  array<int, array{worker: string, payload: array<string, mixed>}>  $log */
        public function __construct(private readonly string $worker, private array &$log)
        {
            parent::__construct();
        }

        protected function send(array $payload): void
        {
            parent::send($payload);
            $this->log[] = ['worker' => $this->worker, 'payload' => $payload];
        }
    };
    app()->instance(TracerExporter::class, $exporter);
    app()->instance(PulseExporter::class, $exporter);
    foreach ([TraceContext::class, ExecutionClock::class, ExecutionContext::class, Graph::class] as $binding) {
        app()->forgetInstance($binding);
    }
    rlForgetExecutionServices();

    return $exporter;
}

/**
 * One root, and every parent id resolves in the merged trace.
 *
 * @param  array<string, mixed>  $merged
 */
function rlAssertNoOrphans(array $merged): void
{
    $ids = array_column($merged['spans'], 'id');
    foreach ($merged['spans'] as $span) {
        if ($span['parent_id'] !== null) {
            expect($ids)->toContain($span['parent_id']);
        }
    }
    expect(array_filter($merged['spans'], fn (array $span): bool => $span['parent_id'] === null))->toHaveCount(1);
}

it('queue fan-out across two workers: every segment is sent and the merge has one root and no orphans', function () {
    rlExporter();
    $sent = [];
    $fake = Bus::fake();
    rlFanOutGraph('rl-two-workers');
    $executionId = 'exec_rl_two_'.bin2hex(random_bytes(4));
    app(ExecutionRepository::class)->queued($executionId, 'rl-two-workers', State::toStoragePayload(new State([])));

    rlWorker('A', $sent);
    app()->call([unserialize(serialize(new ExecuteGraphJob('rl-two-workers', $executionId, [], null, 'span_rl_root'))), 'handle']);

    /** @var PendingBatch|null $pending */
    $pending = null;
    $fake->assertBatched(function (PendingBatch $batch) use (&$pending): bool {
        $pending = $batch;

        return true;
    });
    [$jobA, $jobB] = $pending->jobs->all();
    rlWorker('A', $sent);
    app()->call([unserialize(serialize($jobA)), 'handle']);
    rlWorker('B', $sent);
    app()->call([unserialize(serialize($jobB)), 'handle']);

    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-rl-two';
    unserialize(serialize($pending->thenCallbacks()[0]))->getClosure()($batch);
    rlWorker('B', $sent);
    app()->call([unserialize(serialize($fake->dispatched(ContinueExecutionJob::class)->first())), 'handle']);

    $merged = (new TraceSegmentMerger)->merge(array_column($sent, 'payload'));
    rlAssertNoOrphans($merged);
    $nodeWorkers = [];
    foreach ($sent as $entry) {
        foreach ($entry['payload']['spans'] as $span) {
            if ($span['span_type'] === 'node') {
                $nodeWorkers[$span['node_name']] = $entry['worker'];
            }
        }
    }
    ksort($nodeWorkers);

    expect($nodeWorkers)->toBe(['a' => 'A', 'b' => 'B', 'end' => 'B', 'start' => 'A'])
        ->and($merged['trace']['status'])->toBe('completed')
        ->and($merged['segments'])->toHaveCount(5)
        ->and($merged['trace']['active_ms'])->toBeLessThanOrEqual($merged['trace']['duration_ms'])
        ->and($merged['trace']['ended_at'])->toBe(end($merged['segments'])['ended_at']);
});

it('a failed batch branch sends every segment and closes the trace with a failed final segment', function () {
    rlExporter();
    $sent = [];
    $fake = Bus::fake();
    $graph = GraphBuilder::create('rl-batch-fail')
        ->addNode('start', RlStart::class)
        ->addNode('a', RlBranchA::class)
        ->addNode('b', RlBoom::class)
        ->addNode('end', RlEnd::class)
        ->addConditionalEdge('start', rlRouter())
        ->addJoin(['a', 'b'], 'end')
        ->addEdge('a', 'end')
        ->addEdge('b', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    app(GraphRegistry::class)->register('rl-batch-fail', $graph);
    $executionId = 'exec_rl_bfail_'.bin2hex(random_bytes(4));
    app(ExecutionRepository::class)->queued($executionId, 'rl-batch-fail', State::toStoragePayload(new State([])));

    rlWorker('A', $sent);
    app()->call([unserialize(serialize(new ExecuteGraphJob('rl-batch-fail', $executionId, [], null, 'span_rl_root'))), 'handle']);

    /** @var PendingBatch|null $pending */
    $pending = null;
    $fake->assertBatched(function (PendingBatch $batch) use (&$pending): bool {
        $pending = $batch;

        return true;
    });
    [$jobA, $jobB] = $pending->jobs->all();
    rlWorker('A', $sent);
    app()->call([unserialize(serialize($jobA)), 'handle']);
    rlWorker('B', $sent);
    $error = null;
    try {
        app()->call([unserialize(serialize($jobB)), 'handle']);
    } catch (RuntimeException $e) {
        $error = $e;
    }
    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-rl-fail';
    unserialize(serialize($pending->catchCallbacks()[0]))->getClosure()($batch, $error);

    $merged = (new TraceSegmentMerger)->merge(array_column($sent, 'payload'));
    $root = array_values(array_filter($merged['spans'], fn (array $span): bool => $span['parent_id'] === null))[0];
    rlAssertNoOrphans($merged);

    expect($error)->toBeInstanceOf(RuntimeException::class)
        ->and(array_map(fn (array $entry): string => $entry['worker'].':'.$entry['payload']['segment']['kind'], $sent))
        ->toBe(['A:initial', 'A:continued', 'B:continued', 'B:continued'])
        ->and(end($sent)['payload']['segment']['final'])->toBeTrue()
        ->and($merged['trace']['status'])->toBe('failed')
        ->and($merged['trace']['error']['message'])->toBe('rl boom')
        ->and($root['id'])->toBe('span_rl_root')
        ->and($root['status'])->toBe('failed');
});

it('resume in a new process after the cache is flushed sends the resumed segment and merges into one trace', function (bool $afterTtl) {
    $first = rlExporter();
    $graph = rlLinearGraph('rl-flushed-'.($afterTtl ? 'ttl' : 'cache'), [['start', RlStart::class], ['approve', RlApproval::class], ['end', RlEnd::class]]);
    $state = app(GraphExecutor::class)->execute($graph, new State([]));
    $checkpointId = $state->getInterruptCheckpointId();
    $interrupted = $first->sent;

    // Only the checkpoint survives; with the old cache buffer the resume found no buffered trace and sent nothing.
    $checkpoint = app(StorageInterface::class)->load($checkpointId);
    Cache::flush();
    app(StorageInterface::class)->save($checkpoint);
    if ($afterTtl) {
        $this->travel(2)->hours();
    }

    $sent = [];
    rlWorker('resume', $sent);
    app(GraphExecutor::class)->resumeWithInput($checkpointId, ['ok' => true]);

    $merged = (new TraceSegmentMerger)->merge([...$interrupted, ...array_column($sent, 'payload')]);
    rlAssertNoOrphans($merged);
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['payload']['segment']['kind'])->toBe('resumed')
        ->and($sent[0]['payload']['segment']['final'])->toBeTrue()
        ->and($merged['trace']['status'])->toBe('completed')
        ->and($merged['trace']['started_at'])->toBe($interrupted[0]['trace']['started_at'])
        ->and($merged['trace'])->not->toHaveKey('interrupt_reason');
})->with(['cache flushed' => false, 'after the old buffer TTL' => true]);

it('the interrupt segment ends at the same instant as the interrupted root span', function () {
    $exporter = rlExporter();
    $graph = rlLinearGraph('rl-h2', [['start', RlStart::class], ['approve', RlApproval::class], ['end', RlEnd::class]]);

    app(GraphExecutor::class)->execute($graph, new State([]));

    $payload = $exporter->sent[0];
    $root = assertTraceTree($payload);
    expect($root['ended_at'])->toBe($payload['segment']['ended_at'])
        ->and($payload['trace']['interrupted_at'])->toBe($payload['segment']['ended_at']);
});

it('an interrupt carries its stored expires_at on the interrupt span and the interrupting segment', function () {
    $exporter = rlExporter();
    $graph = rlLinearGraph('rl-expiry', [['start', RlStart::class], ['approve', RlApproval::class], ['end', RlEnd::class]]);

    app(GraphExecutor::class)->execute($graph, new State([]));

    $payload = $exporter->sent[0];
    $interrupt = InterruptRecord::query()->forExecution($payload['trace']['id'])->firstOrFail();
    $expiresAt = TraceTime::format($interrupt->getAttribute('expires_at'));
    $span = collect($payload['spans'])->firstWhere('span_type', 'interrupt');

    expect($payload['trace']['interrupt_expires_at'])->toBe($expiresAt)
        ->and($span['attributes']['interrupt.expires_at'])->toBe($expiresAt);
});

it('an interrupt without a stored expiry carries neither expiry key', function (string $key, mixed $value) {
    config([$key => $value]);
    $exporter = rlExporter();
    $graph = rlLinearGraph('rl-no-expiry', [['start', RlStart::class], ['approve', RlApproval::class], ['end', RlEnd::class]]);

    app(GraphExecutor::class)->execute($graph, new State([]));

    $payload = $exporter->sent[0];
    $span = collect($payload['spans'])->firstWhere('span_type', 'interrupt');
    expect($payload['trace']['status'])->toBe('interrupted')
        ->and($payload['trace'])->not->toHaveKey('interrupt_expires_at')
        ->and($span['attributes'])->not->toHaveKey('interrupt.expires_at');
})->with([
    'expiry off' => ['agenticgraph.interrupts.default_expiry_days', 0],
    'persistence off' => ['agenticgraph.interrupts.persistence_enabled', false],
]);

/**
 * Interrupts a run, then expires it from a fresh process, as the scheduled command does.
 *
 * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} the interrupted root span and every sent segment
 */
function rlExpiredRun(string $name): array
{
    $exporter = rlExporter();
    $graph = rlLinearGraph($name, [['start', RlStart::class], ['approve', RlApproval::class], ['end', RlEnd::class]]);
    app(GraphExecutor::class)->execute($graph, new State([]));
    $interruptedRoot = assertTraceTree($exporter->sent[0]);

    rlNewProcess($exporter);
    InterruptRecord::query()->forExecution($exporter->sent[0]['trace']['id'])->update(['expires_at' => now()->subMinute()]);
    Artisan::call('agenticgraph:interrupt:expire');

    expect($exporter->sent)->toHaveCount(2)
        ->and($exporter->sent[1]['segment']['kind'])->toBe('continued')
        ->and($exporter->sent[1]['segment']['final'])->toBeTrue()
        ->and($exporter->sent[1]['trace']['status'])->toBe('failed');

    return [$interruptedRoot, $exporter->sent];
}

it('interrupt:expire ends the interrupted root span, so the merged trace has one root', function () {
    [$interruptedRoot, $sent] = rlExpiredRun('rl-expire-root');

    $graphSpans = array_values(array_filter($sent[1]['spans'], fn (array $span): bool => $span['span_type'] === 'graph'));
    $merged = (new TraceSegmentMerger)->merge($sent);

    expect($graphSpans)->toHaveCount(1)
        ->and($graphSpans[0]['id'])->toBe($interruptedRoot['id'])
        ->and($graphSpans[0]['status'])->toBe('failed')
        ->and(array_filter($merged['spans'], fn (array $span): bool => $span['parent_id'] === null))->toHaveCount(1);
});

it('interrupt:expire sends a final segment and graph span that end at or after they start', function () {
    [, $sent] = rlExpiredRun('rl-expire-order');

    $segment = $sent[1]['segment'];
    $graphSpan = collect($sent[1]['spans'])->firstWhere('span_type', 'graph');

    expect(rlMicros($segment['ended_at']))->toBeGreaterThanOrEqual(rlMicros($segment['started_at']))
        ->and(rlMicros($graphSpan['ended_at']))->toBeGreaterThanOrEqual(rlMicros($graphSpan['started_at']))
        ->and(rlMicros($sent[1]['trace']['ended_at']))->toBeGreaterThanOrEqual(rlMicros($segment['started_at']));
});

it('interrupt:expire sends the stored graph_name on the final segment', function () {
    [, $sent] = rlExpiredRun('rl-expire-name');

    expect($sent[1]['trace']['graph_name'] ?? null)->toBe('rl-expire-name')
        ->and((new TraceSegmentMerger)->merge($sent)['trace']['graph_name'])->toBe('rl-expire-name');
});

it('interrupt:expire sends no final segment when the expiry rolls back, and exactly one on the next run', function () {
    $exporter = rlExporter();
    $graph = rlLinearGraph('rl-expire-rollback', [['start', RlStart::class], ['approve', RlApproval::class], ['end', RlEnd::class]]);
    app(GraphExecutor::class)->execute($graph, new State([]));
    $traceId = $exporter->sent[0]['trace']['id'];
    $interrupt = InterruptRecord::query()->forExecution($traceId)->firstOrFail();
    $interrupt->update(['expires_at' => now()->subMinute()]);

    rlNewProcess($exporter);
    Event::listen(InterruptExpired::class, fn () => throw new RuntimeException('listener failed'));
    Artisan::call('agenticgraph:interrupt:expire');

    expect($exporter->sent)->toHaveCount(1)
        ->and($interrupt->refresh()->status())->toBe('pending')
        ->and(DB::table('agenticgraph_executions')->where('id', $traceId)->value('status'))->toBe('interrupted');

    rlNewProcess($exporter);
    Event::forget(InterruptExpired::class);
    Artisan::call('agenticgraph:interrupt:expire');

    $finals = array_filter($exporter->sent, fn (array $payload): bool => $payload['segment']['final']);
    expect($exporter->sent)->toHaveCount(2)
        ->and($finals)->toHaveCount(1)
        ->and($exporter->sent[1]['trace']['status'])->toBe('failed')
        ->and($interrupt->refresh()->status())->toBe('expired');
});
