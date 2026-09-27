<?php

declare(strict_types=1);

use Illuminate\Bus\Batch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\ExecutionContextManager;
use Shahzad\AgenticGraph\Execution\ExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptManager;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Routers\AbstractRouter;
use Shahzad\AgenticGraph\Routers\PrismRouter;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumption;
use Shahzad\AgenticGraph\Tests\Support\Routers\Billing\IntentRouter as BillingIntentRouter;
use Shahzad\AgenticGraph\Tests\Support\Routers\Shipping\IntentRouter as ShippingIntentRouter;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\Listeners\TracingListener;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceSegmentMerger;

class RouteSpanExporter extends PulseExporter
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    protected function send(array $payload): void
    {
        $this->sent[] = $payload;
    }
}

class RsSupervisor extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('turns', ((int) $state->get('turns', 0)) + 1);
    }
}

class RsStep extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set($this->getName(), true);
    }
}

class RsDraft extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('drafts', ((int) $state->get('drafts', 0)) + 1);
    }
}

class RsApproval extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
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

class RsSupervisorRouter extends AbstractRouter
{
    public function route(StateContract $state): string
    {
        return ((int) $state->get('turns', 0)) >= 3 ? 'finish' : 'worker';
    }

    public function getAvailableRoutes(): array
    {
        return ['worker', 'finish'];
    }
}

class RsFanOutRouter extends AbstractRouter
{
    public function route(StateContract $state): array
    {
        return ['a', 'b', 'c'];
    }

    public function getAvailableRoutes(): array
    {
        return ['a', 'b', 'c'];
    }
}

class RsRedraftRouter extends AbstractRouter
{
    public function route(StateContract $state): string
    {
        return ((int) $state->get('drafts', 0)) >= 2 ? 'end' : 'draft';
    }

    public function getAvailableRoutes(): array
    {
        return ['draft', 'end'];
    }
}

function rsExporter(): RouteSpanExporter
{
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.endpoint' => 'https://api.example.com',
        'agenticgraph.tracing.api_key' => 'test-key',
        'agenticgraph.checkpoint.enabled' => true,
    ]);
    // Console context: flush sends synchronously.
    app()->forgetInstance('request');

    $exporter = new RouteSpanExporter;
    app()->instance(TracerExporter::class, $exporter);
    app()->instance(PulseExporter::class, $exporter);
    rsForgetExecutionServices();
    Event::subscribe(TracingListener::class);

    return $exporter;
}

function rsForgetExecutionServices(): void
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
 * A fresh PHP process: per-run bindings are gone, the cache (checkpoints) survives.
 */
function rsNewProcess(RouteSpanExporter $exporter): void
{
    $exporter->reset();
    foreach ([TraceContext::class, ExecutionClock::class, ExecutionContext::class, Graph::class] as $binding) {
        app()->forgetInstance($binding);
    }
    rsForgetExecutionServices();
}

function rsRegister(Graph $graph): Graph
{
    app(GraphRegistry::class)->register($graph->name, $graph);

    return $graph;
}

function rsFanOutGraph(string $name): Graph
{
    return rsRegister(GraphBuilder::create($name)
        ->addNode('start', RsStep::class)
        ->addNode('a', RsStep::class)
        ->addNode('b', RsStep::class)
        ->addNode('c', RsStep::class)
        ->addNode('end', RsStep::class)
        ->addConditionalEdge('start', RsFanOutRouter::class)
        ->addJoin(['a', 'b', 'c'], 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build());
}

/**
 * draft -> approve (interrupts once) -> draft again -> approve -> end.
 */
function rsRedraftGraph(string $name): Graph
{
    return rsRegister(GraphBuilder::create($name)
        ->addNode('draft', RsDraft::class)
        ->addNode('approve', RsApproval::class)
        ->addNode('end', RsStep::class)
        ->addEdge('draft', 'approve')
        ->addConditionalEdge('approve', RsRedraftRouter::class)
        ->setEntryPoint('draft')
        ->addExitPoint('end')
        ->build());
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<int, array<string, mixed>>
 */
function rsSpans(array $payload, string $type): array
{
    return array_values(array_filter($payload['spans'], fn (array $span): bool => $span['span_type'] === $type));
}

/**
 * Node name and visit count of each node span, in start order.
 *
 * @param  array<string, mixed>  $payload
 * @return array<int, string>
 */
function rsVisits(array $payload): array
{
    $nodes = rsSpans($payload, 'node');
    usort($nodes, fn (array $x, array $y): int => rsMicros($x['started_at']) <=> rsMicros($y['started_at']));

    return array_map(fn (array $span): string => $span['node_name'].':'.($span['loop_context']['node_visit_count'] ?? 'none'), $nodes);
}

function rsMicros(string $at): int
{
    return (int) (new DateTimeImmutable($at))->format('Uu');
}

/**
 * One root, no orphans, and every route span is a child of the root inside its window.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed> the root span
 */
function rsAssertRoutesUnderRoot(array $payload): array
{
    $ids = array_column($payload['spans'], 'id');
    $roots = array_values(array_filter($payload['spans'], fn (array $span): bool => $span['parent_id'] === null));
    expect($roots)->toHaveCount(1)
        ->and($roots[0]['span_type'])->toBe('graph');
    foreach ($payload['spans'] as $span) {
        if ($span['parent_id'] !== null) {
            expect($ids)->toContain($span['parent_id']);
        }
    }

    $root = $roots[0];
    foreach (rsSpans($payload, 'route') as $route) {
        expect($route['parent_id'])->toBe($root['id'])
            ->and(rsMicros($route['started_at']))->toBeGreaterThanOrEqual(rsMicros($root['started_at']))
            ->and(rsMicros($route['ended_at']))->toBeLessThanOrEqual(rsMicros($root['ended_at']));
    }

    return $root;
}

afterEach(function () {
    Cache::flush();
});

it('emits one route span per supervisor visit with the chosen target', function () {
    $exporter = rsExporter();
    $graph = rsRegister(GraphBuilder::create('rs-supervisor')
        ->addNode('supervisor', RsSupervisor::class)
        ->addNode('worker', RsStep::class)
        ->addNode('finish', RsStep::class)
        ->addConditionalEdge('supervisor', RsSupervisorRouter::class)
        ->addEdge('worker', 'supervisor')
        ->setEntryPoint('supervisor')
        ->addExitPoint('finish')
        ->build());

    app(GraphExecutor::class)->execute($graph, new State([]));

    $payload = $exporter->sent[0];
    rsAssertRoutesUnderRoot($payload);
    $routes = rsSpans($payload, 'route');
    expect(array_map(fn (array $route): array => $route['attributes']['route.targets'], $routes))->toBe([['worker'], ['worker'], ['finish']]);
    foreach ($routes as $route) {
        expect($route['node_name'])->toBe('supervisor route')
            ->and($route['attributes']['route.from'])->toBe('supervisor')
            ->and($route['attributes']['route.kind'])->toBe('deterministic')
            ->and($route['attributes']['route.router'])->toBe(RsSupervisorRouter::class)
            ->and($route['attributes']['route.from_cache'])->toBeFalse()
            ->and($route['attributes']['route.fallback'])->toBeFalse()
            ->and(array_filter(array_keys($route), fn (string $key): bool => str_starts_with($key, 'route.')))->toBe([]);
    }
    expect(rsVisits($payload))->toBe(['supervisor:1', 'worker:1', 'supervisor:2', 'worker:2', 'supervisor:3', 'finish:1']);
});

it('names routers by fully qualified class, so routers sharing a short name stay distinct', function () {
    $exporter = rsExporter();
    $routers = [];
    foreach ([BillingIntentRouter::class, ShippingIntentRouter::class] as $i => $router) {
        $graph = rsRegister(GraphBuilder::create('rs-same-short-name-'.$i)
            ->addNode('start', RsStep::class)
            ->addNode('end', RsStep::class)
            ->addConditionalEdge('start', $router)
            ->setEntryPoint('start')
            ->addExitPoint('end')
            ->build());
        app(GraphExecutor::class)->execute($graph, new State([]));
        $routers[] = rsSpans(end($exporter->sent), 'route')[0]['attributes']['route.router'];
    }

    expect($routers)->toBe([BillingIntentRouter::class, ShippingIntentRouter::class])
        ->and(class_basename($routers[0]))->toBe(class_basename($routers[1]));
});

it('emits no route span for a static edge', function () {
    $exporter = rsExporter();
    $graph = rsRegister(GraphBuilder::create('rs-static')
        ->addNode('one', RsStep::class)
        ->addNode('two', RsStep::class)
        ->addEdge('one', 'two')
        ->setEntryPoint('one')
        ->addExitPoint('two')
        ->build());

    app(GraphExecutor::class)->execute($graph, new State([]));

    expect(rsSpans($exporter->sent[0], 'route'))->toBe([]);
});

it('sync fan-out emits one fan_out route span, one join span, and counts each branch visit', function () {
    $exporter = rsExporter();

    app(GraphExecutor::class)->execute(rsFanOutGraph('rs-sync-fanout'), new State([]));

    $payload = $exporter->sent[0];
    rsAssertRoutesUnderRoot($payload);
    [$fanOut, $join] = rsSpans($payload, 'route');
    expect($fanOut['attributes']['route.from'])->toBe('start')
        ->and($fanOut['attributes']['route.targets'])->toBe(['a', 'b', 'c'])
        ->and($fanOut['attributes']['route.kind'])->toBe('fan_out')
        ->and($join['attributes']['route.from'])->toBe('a|b|c')
        ->and($join['node_name'])->toBe('a|b|c route')
        ->and($join['attributes']['route.targets'])->toBe(['end'])
        ->and($join['attributes']['route.kind'])->toBe('join')
        ->and($join['attributes']['route.router'])->toBeNull()
        ->and(rsVisits($payload))->toBe(['start:1', 'a:1', 'b:1', 'c:1', 'end:1']);
});

it('an LLM router records kind llm, a cache hit, and parents its LLM span on the route span', function () {
    config([
        'agenticgraph.prism.track_tokens' => true,
        'agenticgraph.prism.cache_enabled' => true,
    ]);
    $fake = fn (): StructuredResponseFake => StructuredResponseFake::make()
        ->withStructured(['route' => 'b'])
        ->withUsage(new Usage(3, 4))
        ->withMeta(new Meta('fake-route', 'fake-model'));
    Prism::fake([$fake(), $fake()]);
    $graph = rsRegister(GraphBuilder::create('rs-llm')
        ->addNode('start', RsStep::class)
        ->addNode('a', RsStep::class)
        ->addNode('b', RsStep::class)
        ->addConditionalEdge('start', new PrismRouter(['a' => 'first', 'b' => 'second'], defaultRoute: 'a'))
        ->setEntryPoint('start')
        ->addExitPoint('a')
        ->addExitPoint('b')
        ->build());
    $exporter = rsExporter();

    app(GraphExecutor::class)->execute($graph, new State([]));
    app(GraphExecutor::class)->execute($graph, new State([]));

    [$first, $second] = $exporter->sent;
    rsAssertRoutesUnderRoot($first);
    $route = rsSpans($first, 'route')[0];
    $llm = rsSpans($first, 'llm')[0];
    expect($route['attributes']['route.kind'])->toBe('llm')
        ->and($route['attributes']['route.router'])->toBe(PrismRouter::class)
        ->and($route['attributes']['route.targets'])->toBe(['b'])
        ->and($route['attributes']['route.from_cache'])->toBeFalse()
        ->and($llm['parent_id'])->toBe($route['id'])
        ->and(rsMicros($llm['started_at']))->toBeGreaterThanOrEqual(rsMicros($route['started_at']))
        ->and(rsMicros($llm['ended_at']))->toBeLessThanOrEqual(rsMicros($route['ended_at']));

    rsAssertRoutesUnderRoot($second);
    expect(rsSpans($second, 'route')[0]['attributes']['route.from_cache'])->toBeTrue()
        ->and(rsSpans($second, 'llm'))->toBe([]);
});

it('counts visits correctly after a sync resume', function () {
    $exporter = rsExporter();
    $graph = rsRedraftGraph('rs-resume');

    $state = app(GraphExecutor::class)->execute($graph, new State([]));
    expect(rsVisits($exporter->sent[0]))->toBe(['draft:1']);

    rsNewProcess($exporter);
    app(GraphExecutor::class)->resumeWithInput($state->getInterruptCheckpointId(), ['ok' => true]);

    $resumed = end($exporter->sent);
    rsAssertRoutesUnderRoot($resumed);
    expect(rsVisits($resumed))->toBe(['approve:1', 'draft:2', 'approve:2', 'end:1']);
});

it('counts visits correctly after a streaming resume', function () {
    $exporter = rsExporter();
    $graph = rsRedraftGraph('rs-stream-resume');

    $state = app(GraphExecutor::class)->execute($graph, new State([]));

    rsNewProcess($exporter);
    app(StreamResumption::class)
        ->resumeStream($state->getInterruptCheckpointId(), ['ok' => true], new StreamOptions(delivery: 'iterator'))
        ->getFinalState();

    $resumed = end($exporter->sent);
    rsAssertRoutesUnderRoot($resumed);
    expect(rsVisits($resumed))->toBe(['approve:1', 'draft:2', 'approve:2', 'end:1']);
});

it('an interrupt checkpoint stores the loop context', function () {
    rsExporter();

    $state = app(GraphExecutor::class)->execute(rsRedraftGraph('rs-checkpoint-loop'), new State([]));

    $loop = app(StorageInterface::class)->load($state->getInterruptCheckpointId())->metadata['loop'];
    expect($loop['node_visit_counts'])->toBe(['draft' => 1, 'approve' => 1]);
});

it('an old checkpoint without loop metadata resumes with a fresh loop context', function () {
    $exporter = rsExporter();
    $state = app(GraphExecutor::class)->execute(rsRedraftGraph('rs-old-checkpoint'), new State([]));
    $checkpointId = $state->getInterruptCheckpointId();
    $storage = app(StorageInterface::class);
    $checkpoint = $storage->load($checkpointId);
    unset($checkpoint->metadata['loop']);
    $storage->save($checkpoint);

    rsNewProcess($exporter);
    app(GraphExecutor::class)->resumeWithInput($checkpointId, ['ok' => true]);

    // Nothing before the interrupt is known, so counting restarts at the resumed node.
    expect(rsVisits(end($exporter->sent)))->toBe(['approve:1', 'draft:1', 'approve:2', 'end:1']);
});

it('queue fan-out carries the loop context to branch jobs and the continuation, and the join is its own segment', function () {
    $exporter = rsExporter();
    $fake = Bus::fake();
    rsFanOutGraph('rs-queue-fanout');
    $executionId = 'exec_rs_queue_'.bin2hex(random_bytes(4));
    app(ExecutionRepository::class)->queued($executionId, 'rs-queue-fanout', State::toStoragePayload(new State([])));

    app()->call([unserialize(serialize(new ExecuteGraphJob('rs-queue-fanout', $executionId, [], null, 'span_rs_root'))), 'handle']);

    /** @var PendingBatch|null $pending */
    $pending = null;
    $fake->assertBatched(function (PendingBatch $batch) use (&$pending): bool {
        $pending = $batch;

        return true;
    });
    foreach ($pending->jobs as $job) {
        expect($job->loopMetadata['node_visit_counts'])->toBe(['start' => 1]);
        app()->forgetInstance(ExecutionContext::class);
        app()->call([unserialize(serialize($job)), 'handle']);
    }

    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-rs';
    unserialize(serialize($pending->thenCallbacks()[0]))->getClosure()($batch);
    $continue = $fake->dispatched(ContinueExecutionJob::class)->first();
    app()->forgetInstance(ExecutionContext::class);
    app()->call([unserialize(serialize($continue)), 'handle']);

    $join = array_values(array_filter($exporter->sent, fn (array $sent): bool => rsSpans($sent, 'route') !== [] && rsSpans($sent, 'node') === []));
    expect($join)->toHaveCount(1)
        ->and(rsSpans($join[0], 'route')[0]['attributes']['route.kind'])->toBe('join');

    $merged = (new TraceSegmentMerger)->merge($exporter->sent);
    $root = rsAssertRoutesUnderRoot($merged);
    expect($root['id'])->toBe('span_rs_root')
        ->and(array_map(fn (array $route): string => $route['attributes']['route.kind'], rsSpans($merged, 'route')))->toBe(['fan_out', 'join'])
        ->and(rsVisits($merged))->toBe(['start:1', 'a:1', 'b:1', 'c:1', 'end:1']);
});

it('an ExecuteNodeJob serialized before loopMetadata existed runs without a loop context', function () {
    $exporter = rsExporter();
    rsFanOutGraph('rs-old-node-job');
    $exporter->startTrace('exec_rs_old_node', 'rs-old-node-job');

    $serialized = serialize(new ExecuteNodeJob('exec_rs_old_node', 'a', [], 'rs-old-node-job', 'span_rs_root'));
    $serialized = preg_replace('/s:12:"loopMetadata";N;/', '', $serialized);
    $serialized = preg_replace_callback('/^O:(\d+):"([^"]+)":(\d+):/', fn (array $m): string => "O:{$m[1]}:\"{$m[2]}\":".((int) $m[3] - 1).':', $serialized);
    $job = unserialize($serialized);

    expect(isset($job->loopMetadata))->toBeFalse();
    app()->forgetInstance(ExecutionContext::class);
    app()->call([$job, 'handle']);

    expect(rsSpans($exporter->sent[0], 'node')[0]['loop_context'])->toBeNull()
        ->and(app()->bound(ExecutionContext::class))->toBeFalse()
        ->and(Cache::get('agenticgraph:batch:exec_rs_old_node:a'))->toBeArray();
});

it('an ExecuteNodeJob run inline restores the loop context bound around it', function () {
    rsExporter();
    rsFanOutGraph('rs-inline-node-job');
    $outer = new ExecutionContext('exec_rs_inline', microtime(true));
    app()->instance(ExecutionContext::class, $outer);

    $job = new ExecuteNodeJob('exec_rs_inline', 'a', [], 'rs-inline-node-job', 'span_rs_root', ['node_visit_counts' => ['start' => 1]]);
    app()->call([$job, 'handle']);

    expect(app(ExecutionContext::class))->toBe($outer);
});
