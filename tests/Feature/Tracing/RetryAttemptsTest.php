<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Execution\ExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\Listeners\TracingListener;
use Shahzad\AgenticGraph\Tracing\PulseExporter;

class RetryAttemptsExporter extends PulseExporter
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    protected function send(array $payload): void
    {
        $this->sent[] = $payload;
    }
}

/**
 * Fails its first $failures attempts, then succeeds. Every attempt makes one LLM call first.
 */
class RaFlakyNode extends AbstractNode
{
    public const DELAY_MS = 20;

    public static int $failures = 0;

    public static int $calls = 0;

    public function getRetryDelay(): int
    {
        return self::DELAY_MS;
    }

    public function execute(StateContract $state): StateContract
    {
        self::$calls++;
        $start = microtime(true);
        usleep(2000);
        event(TokensUsed::fromLlmCall('flaky', $start, (microtime(true) - $start) * 1000, 1, 1, 'text', 'test:model'));

        if (self::$calls <= self::$failures) {
            throw new RuntimeException('flaky attempt '.self::$calls);
        }

        return $state->set('flaky_done', true);
    }
}

class RaStreamThenThrowNode extends AbstractNode
{
    public static int $calls = 0;

    public function getRetryDelay(): int
    {
        return 1;
    }

    public function execute(StateContract $state): StateContract
    {
        self::$calls++;
        $this->streamEvent('text_delta', ['delta' => 'partial']);
        throw new RuntimeException('failed after streaming');
    }
}

/**
 * Node middleware for the flaky node only: throws before $next, throws after $next returns,
 * or catches and rethrows the node's own exception.
 */
class RaGuardMiddleware
{
    public static string $mode = 'rethrow';

    public function handle(StateContract $state, NodeInterface $node, Closure $next): StateContract
    {
        if (! $node instanceof RaFlakyNode) {
            return $next($state);
        }
        if (self::$mode === 'before') {
            throw new RuntimeException('middleware rejected before next');
        }

        try {
            $result = $next($state);
        } catch (Throwable $e) {
            throw $e;
        }
        if (self::$mode === 'after') {
            throw new RuntimeException('middleware rejected the result');
        }

        return $result;
    }
}

class RaStartNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('started', true);
    }
}

function raExporter(): RetryAttemptsExporter
{
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.endpoint' => 'https://api.example.com',
        'agenticgraph.tracing.api_key' => 'test-key',
    ]);
    // Console context: flush sends synchronously.
    app()->forgetInstance('request');

    $exporter = new RetryAttemptsExporter;
    app()->instance(TracerExporter::class, $exporter);
    app()->instance(PulseExporter::class, $exporter);
    foreach ([ParallelExecutionCoordinator::class, ExecutionCoordinator::class, GraphExecutor::class] as $service) {
        app()->forgetInstance($service);
    }
    Event::subscribe(TracingListener::class);

    return $exporter;
}

function raGraph(string $name, string $nodeClass): Graph
{
    $graph = GraphBuilder::create($name)
        ->addNode('flaky', $nodeClass)
        ->setEntryPoint('flaky')
        ->addExitPoint('flaky')
        ->build();
    app(GraphRegistry::class)->register($name, $graph);

    return $graph;
}

function raFanOutGraph(string $name): Graph
{
    $router = new class implements RouterInterface
    {
        public function route(StateContract $state): string|array
        {
            return ['flaky', 'other'];
        }

        public function getAvailableRoutes(): array
        {
            return ['flaky', 'other'];
        }

        public function getDefaultRoute(): ?string
        {
            return null;
        }

        public function validateRoute(string $route): bool
        {
            return in_array($route, ['flaky', 'other'], true);
        }
    };

    $graph = GraphBuilder::create($name)
        ->addNode('start', RaStartNode::class)
        ->addNode('flaky', RaFlakyNode::class)
        ->addNode('other', RaStartNode::class)
        ->addNode('end', RaStartNode::class)
        ->addConditionalEdge('start', $router)
        ->addJoin(['flaky', 'other'], 'end')
        ->addEdge('flaky', 'end')
        ->addEdge('other', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    app(GraphRegistry::class)->register($name, $graph);

    return $graph;
}

/**
 * Attempt spans of one node, ordered by attempt number.
 *
 * @param  array<int, array<string, mixed>>  $spans
 * @return array<int, array<string, mixed>>
 */
function raAttempts(array $spans, string $node = 'flaky'): array
{
    $attempts = array_values(array_filter(
        $spans,
        fn (array $span): bool => $span['span_type'] === 'node' && $span['node_name'] === $node
    ));
    usort($attempts, fn (array $a, array $b): int => $a['attempt'] <=> $b['attempt']);

    return $attempts;
}

function raMicros(string $at): int
{
    return (int) (new DateTimeImmutable($at))->format('Uu');
}

/**
 * Four attempts failed: ids, attempt numbers, attempt_of, node_type, parents, and the retry gap.
 *
 * @param  array<int, array<string, mixed>>  $attempts
 */
function raAssertFourFailedAttempts(array $attempts, string $rootId): void
{
    expect($attempts)->toHaveCount(4)
        ->and(array_unique(array_column($attempts, 'id')))->toHaveCount(4)
        ->and(array_column($attempts, 'attempt'))->toBe([1, 2, 3, 4])
        ->and(array_unique(array_column($attempts, 'attempt_of')))->toBe([$attempts[0]['id']]);

    foreach ($attempts as $i => $attempt) {
        expect($attempt['status'])->toBe('failed')
            ->and($attempt['node_type'])->toBe(RaFlakyNode::class)
            ->and($attempt['parent_id'])->toBe($rootId)
            ->and($attempt)->not->toHaveKey('retry_count');
        if ($i > 0) {
            $gapMs = (raMicros($attempt['started_at']) - raMicros($attempts[$i - 1]['ended_at'])) / 1000;
            expect($gapMs)->toBeGreaterThanOrEqual(RaFlakyNode::DELAY_MS)
                ->and($gapMs)->toBeLessThan(RaFlakyNode::DELAY_MS + 25);
        }
    }
}

/**
 * @param  array<int, array<string, mixed>>  $attempts
 */
function raAssertSuccessAfterTwoFailures(array $attempts, string $rootId): void
{
    expect($attempts)->toHaveCount(3)
        ->and(array_column($attempts, 'attempt'))->toBe([1, 2, 3])
        ->and(array_column($attempts, 'status'))->toBe(['failed', 'failed', 'success'])
        ->and(array_unique(array_column($attempts, 'attempt_of')))->toBe([$attempts[0]['id']])
        ->and($attempts[0])->not->toHaveKey('state_diff')
        ->and($attempts[1])->not->toHaveKey('state_diff')
        ->and($attempts[2]['state_diff']['added'])->toContain('flaky_done');

    foreach ($attempts as $attempt) {
        expect($attempt['parent_id'])->toBe($rootId)
            ->and($attempt['node_type'])->toBe(RaFlakyNode::class);
    }
}

function raRootId(array $payload): string
{
    $roots = array_values(array_filter($payload['spans'], fn (array $span): bool => $span['parent_id'] === null));
    expect($roots)->toHaveCount(1)
        ->and($roots[0]['span_type'])->toBe('graph');

    return $roots[0]['id'];
}

beforeEach(function () {
    RaFlakyNode::$calls = 0;
    RaFlakyNode::$failures = 0;
    RaStreamThenThrowNode::$calls = 0;
});

afterEach(function () {
    Cache::flush();
});

it('sync: a node that fails 4 times emits 4 attempt spans under the root', function () {
    $exporter = raExporter();
    RaFlakyNode::$failures = 99;

    expect(fn () => app(GraphExecutor::class)->execute(raGraph('ra-sync-fail', RaFlakyNode::class), new State([])))
        ->toThrow(RuntimeException::class);

    $payload = $exporter->sent[0];
    raAssertFourFailedAttempts(raAttempts($payload['spans']), raRootId($payload));
});

it('sync: fails twice, then succeeds, with state_diff only on the successful attempt', function () {
    $exporter = raExporter();
    RaFlakyNode::$failures = 2;

    app(GraphExecutor::class)->execute(raGraph('ra-sync-recover', RaFlakyNode::class), new State([]));

    $payload = $exporter->sent[0];
    expect($payload['trace']['status'])->toBe('completed');
    raAssertSuccessAfterTwoFailures(raAttempts($payload['spans']), raRootId($payload));
});

it('sync: each attempt parents its own llm span', function () {
    $exporter = raExporter();
    RaFlakyNode::$failures = 2;

    app(GraphExecutor::class)->execute(raGraph('ra-sync-llm', RaFlakyNode::class), new State([]));

    $spans = $exporter->sent[0]['spans'];
    $llm = array_values(array_filter($spans, fn (array $span): bool => $span['span_type'] === 'llm'));
    expect($llm)->toHaveCount(3);
    foreach (raAttempts($spans) as $attempt) {
        $children = array_values(array_filter($llm, fn (array $span): bool => $span['parent_id'] === $attempt['id']));
        expect($children)->toHaveCount(1)
            ->and(raMicros($children[0]['started_at']))->toBeGreaterThanOrEqual(raMicros($attempt['started_at']))
            ->and(raMicros($children[0]['ended_at']))->toBeLessThanOrEqual(raMicros($attempt['ended_at']));
    }
});

it('sync: retries are one visit and every attempt points at attempt 1', function () {
    $exporter = raExporter();
    RaFlakyNode::$failures = 2;

    app(GraphExecutor::class)->execute(raGraph('ra-sync-visit', RaFlakyNode::class), new State([]));

    $attempts = raAttempts($exporter->sent[0]['spans']);
    expect($attempts[2]['loop_context']['node_visit_count'])->toBe(1);
    foreach ($attempts as $attempt) {
        expect($attempt['attempt_of'])->toBe($attempts[0]['id']);
    }
});

it('queue: ExecuteNodeJob on one worker emits one span per attempt under the root', function (int $failures) {
    $exporter = raExporter();
    RaFlakyNode::$failures = $failures;
    raGraph('ra-queue', RaFlakyNode::class);
    $executionId = 'exec_ra_queue_'.bin2hex(random_bytes(4));
    $exporter->startTrace($executionId, 'ra-queue');

    $job = unserialize(serialize(new ExecuteNodeJob($executionId, 'flaky', [], 'ra-queue', 'span_ra_root')));
    try {
        app()->call([$job, 'handle']);
    } catch (RuntimeException) {
    }

    // The job sends its spans as a segment when it ends.
    expect($exporter->sent)->toHaveCount(1);
    $attempts = raAttempts($exporter->sent[0]['spans']);
    if ($failures > 3) {
        raAssertFourFailedAttempts($attempts, 'span_ra_root');
    } else {
        raAssertSuccessAfterTwoFailures($attempts, 'span_ra_root');
    }
})->with(['fails 4 times' => 99, 'fails twice, then succeeds' => 2]);

it('streaming: retries emit one span per attempt under the root', function (int $failures) {
    $exporter = raExporter();
    RaFlakyNode::$failures = $failures;
    AgenticGraph::register('ra-stream', raGraph('ra-stream', RaFlakyNode::class));
    $options = new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR);

    try {
        AgenticGraph::execute('ra-stream', new State, $options)->getFinalState();
    } catch (RuntimeException) {
    }

    $payload = $exporter->sent[0];
    if ($failures > 3) {
        raAssertFourFailedAttempts(raAttempts($payload['spans']), raRootId($payload));
    } else {
        expect($payload['trace']['status'])->toBe('completed');
        raAssertSuccessAfterTwoFailures(raAttempts($payload['spans']), raRootId($payload));
    }
})->with(['fails 4 times' => 99, 'fails twice, then succeeds' => 2]);

it('streaming: a node that fails after emitting content is not retried', function () {
    $exporter = raExporter();
    AgenticGraph::register('ra-stream-d27', raGraph('ra-stream-d27', RaStreamThenThrowNode::class));
    $options = new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR);

    try {
        AgenticGraph::execute('ra-stream-d27', new State, $options)->getFinalState();
    } catch (RuntimeException) {
    }

    $payload = $exporter->sent[0];
    $attempts = raAttempts($payload['spans']);
    expect(RaStreamThenThrowNode::$calls)->toBe(1)
        ->and($attempts)->toHaveCount(1)
        ->and($attempts[0]['status'])->toBe('failed')
        ->and($attempts[0]['attempt'])->toBe(1)
        ->and($attempts[0]['parent_id'])->toBe(raRootId($payload));
});

it('sync fan-out: every attempt of a retried branch parents the root', function () {
    $exporter = raExporter();
    RaFlakyNode::$failures = 2;

    app(GraphExecutor::class)->execute(raFanOutGraph('ra-fanout'), new State([]));

    $payload = $exporter->sent[0];
    raAssertSuccessAfterTwoFailures(raAttempts($payload['spans']), raRootId($payload));
});

/**
 * Run the flaky node on one execution path and return every span the exporter got.
 *
 * @return array{0: array<int, array<string, mixed>>, 1: string}
 */
function raRunPath(RetryAttemptsExporter $exporter, string $path): array
{
    $name = 'ra-mw-'.$path.'-'.bin2hex(random_bytes(3));

    if ($path === 'queue') {
        raGraph($name, RaFlakyNode::class);
        $executionId = 'exec_'.$name;
        $exporter->startTrace($executionId, $name);
        $job = unserialize(serialize(new ExecuteNodeJob($executionId, 'flaky', [], $name, 'span_ra_root')));
        try {
            app()->call([$job, 'handle']);
        } catch (RuntimeException) {
        }

        return [$exporter->sent[0]['spans'] ?? [], 'span_ra_root'];
    }

    if ($path === 'streaming') {
        AgenticGraph::register($name, raGraph($name, RaFlakyNode::class));
    }

    try {
        match ($path) {
            'sync' => app(GraphExecutor::class)->execute(raGraph($name, RaFlakyNode::class), new State([])),
            'fan-out' => app(GraphExecutor::class)->execute(raFanOutGraph($name), new State([])),
            'streaming' => AgenticGraph::execute($name, new State, new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR))
                ->getFinalState(),
        };
    } catch (RuntimeException) {
    }

    $payload = $exporter->sent[0];

    return [$payload['spans'], raRootId($payload)];
}

it('middleware failing before $next still emits one failed attempt span', function (string $path) {
    $exporter = raExporter();
    config(['agenticgraph.middleware.global' => [RaGuardMiddleware::class]]);
    RaGuardMiddleware::$mode = 'before';

    [$spans, $rootId] = raRunPath($exporter, $path);
    $attempts = raAttempts($spans);

    expect(RaFlakyNode::$calls)->toBe(0)
        ->and($attempts)->toHaveCount(1)
        ->and($attempts[0]['status'])->toBe('failed')
        ->and($attempts[0]['attempt'])->toBe(1)
        ->and($attempts[0]['attempt_of'])->toBe($attempts[0]['id'])
        ->and($attempts[0]['error']['message'] ?? null)->toBe('middleware rejected before next')
        ->and($attempts[0]['node_type'])->toBe(RaFlakyNode::class)
        ->and($attempts[0]['parent_id'])->toBe($rootId);
})->with(['sync', 'fan-out', 'queue', 'streaming']);

it('middleware failing after $next marks the successful attempt failed', function (string $path) {
    $exporter = raExporter();
    config(['agenticgraph.middleware.global' => [RaGuardMiddleware::class]]);
    RaGuardMiddleware::$mode = 'after';
    RaFlakyNode::$failures = 1;

    [$spans, $rootId] = raRunPath($exporter, $path);
    $attempts = raAttempts($spans);

    expect(RaFlakyNode::$calls)->toBe(2)
        ->and($attempts)->toHaveCount(2)
        ->and(array_column($attempts, 'attempt'))->toBe([1, 2])
        ->and(array_column($attempts, 'status'))->toBe(['failed', 'failed'])
        ->and($attempts[1]['error']['message'] ?? null)->toBe('middleware rejected the result')
        ->and($attempts[1]['attempt_of'])->toBe($attempts[0]['id'])
        ->and($attempts[1]['parent_id'])->toBe($rootId);
})->with(['sync', 'fan-out', 'queue', 'streaming']);

it('guard: a middleware that rethrows the node exception adds no duplicate spans', function (string $path) {
    $exporter = raExporter();
    config(['agenticgraph.middleware.global' => [RaGuardMiddleware::class]]);
    RaGuardMiddleware::$mode = 'rethrow';
    RaFlakyNode::$failures = 99;

    [$spans, $rootId] = raRunPath($exporter, $path);

    raAssertFourFailedAttempts(raAttempts($spans), $rootId);
    expect(array_filter($spans, fn (array $s): bool => $s['span_type'] === 'node' && $s['node_name'] === 'flaky'))->toHaveCount(4);
})->with(['sync', 'fan-out', 'queue', 'streaming']);
