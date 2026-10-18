<?php

declare(strict_types=1);

/**
 * Contract-freeze guard tests (trace wire v1).
 *
 * The key registry at the end of spec/tracing-wire-v1.md is normative. These tests drive real
 * runs through the file exporter and fail when the code emits a key or value the registry does
 * not list, when a registry key is never emitted, or when a registry key is missing from the
 * prose of the wire doc. v1 only grows: an addition updates the registry, the doc tables and
 * the CHANGELOG together.
 */

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Tool;
use Shahzad\AgenticGraph\AgenticGraphServiceProvider;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Events\ToolCallCaptured;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\ExecutionContextManager;
use Shahzad\AgenticGraph\Execution\ExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptManager;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Facades\Trace;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Routers\AbstractRouter;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\Exporters\FileExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TracePayloadBuilder;
use Shahzad\AgenticGraph\Tracing\TraceSegmentMerger;

class FzWorkNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        Trace::span('custom-step', fn () => null);
        fzSwallow(fn () => Trace::span('custom-fail', fn () => throw new RuntimeException('custom failed')));
        $vector = ['vector.store' => 'pgvector', 'vector.top_k' => 3];
        Trace::span('search', fn () => null, $vector, 'vector');
        // What a job queued here carries, for the queued-work segment sent after the run.
        $context = TraceContext::tryGet();
        $GLOBALS['fzQueued'] = ['trace_id' => $context?->getExecutionId(), 'root_span_id' => $context?->getRootSpanId(), 'span_id' => $context?->getSpanId()];
        fzSwallow(fn () => Trace::span('search-fail', fn () => throw new RuntimeException('vector failed'), $vector, 'vector'));

        $tool = (new Tool)->as('lookup')->for('Look up a city')->withStringParameter('city', 'City')
            ->using(function (string $city): string {
                Http::get('https://api.freeze.test/ok', ['q' => $city]);
                Http::get('https://api.freeze.test/fail');
                fzSwallow(fn () => Http::get('https://down.freeze.test/'));
                DB::select('select 1');

                return 'sunny';
            });
        $this->wrapToolsForTracing([$tool], 'prism')[0]->handle('Lisbon');

        $start = microtime(true);
        event(TokensUsed::fromLlmCall('work', $start, 1.0, 3, 5, 'text', 'test:model', 'prompt', 'response', streamed: true));
        event(TokensUsed::fromCacheHit('work', $start, 0.5, 'test:model', 'prompt', 'response', 'cache:key'));
        event(new ToolCallCaptured(
            executionId: TraceContext::tryGet()?->getExecutionId() ?? '',
            spanId: TraceContext::tryGet()?->getSpanId(),
            toolName: 'provider_tool',
            toolCallId: 'call_1',
            toolType: 'prism',
            inputSize: 10,
            outputSize: 20,
            durationMs: 1.0,
            startedAt: new DateTimeImmutable,
            toolProvider: 'openai',
            toolRequestId: 'req_1',
        ));

        return $state->set('worked', true);
    }
}

class FzApproval extends PrismNode
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

class FzBoomNode extends PrismNode
{
    public function getRetryAttempts(): int
    {
        return 0;
    }

    public function execute(StateContract $state): StateContract
    {
        $tool = (new Tool)->as('lookup')->for('Look up a city')->withStringParameter('city', 'City')
            ->using(function (string $city): string {
                Http::get('https://user:secret@api.freeze.test/ok', ['q' => $city]);
                DB::select('select 1');

                return 'sunny';
            });
        $this->wrapToolsForTracing([$tool], 'prism')[0]->handle('Lisbon');

        throw new RuntimeException('boom');
    }
}

class FzRouter extends AbstractRouter
{
    public function route(StateContract $state): string
    {
        return 'approve';
    }

    public function getAvailableRoutes(): array
    {
        return ['approve'];
    }
}

function fzSwallow(callable $callback): void
{
    try {
        $callback();
    } catch (RuntimeException|ConnectionException) {
    }
}

function fzDir(): string
{
    return sys_get_temp_dir().'/agenticgraph-freeze-test-'.getmypid();
}

/**
 * @return array<string, mixed>
 */
function fzRegistry(): array
{
    $doc = (string) file_get_contents(dirname(__DIR__, 3).'/spec/tracing-wire-v1.md');
    $section = substr($doc, (int) strpos($doc, '## Key registry'));
    preg_match('/```json\n(.*?)\n```/s', $section, $match);

    return json_decode($match[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
}

function fzProse(): string
{
    $doc = (string) file_get_contents(dirname(__DIR__, 3).'/spec/tracing-wire-v1.md');

    return substr($doc, 0, (int) strpos($doc, '## Key registry'));
}

function fzForgetRun(): void
{
    foreach ([TraceContext::class, ExecutionClock::class, ExecutionContext::class, Graph::class, ExecutionContextManager::class, InterruptManager::class, ParallelExecutionCoordinator::class, ExecutionCoordinator::class, GraphExecutor::class] as $binding) {
        app()->forgetInstance($binding);
    }
}

/**
 * Run A: summary capture depths, interrupt then resume in a fresh process, route, custom,
 * vector, tool, http (200, 500, connection failure), db, streamed and cached llm calls, then a
 * job queued by the work node that finishes after the run (a continued segment with a linked span).
 * Run B: full capture depths and a failing node. Plus the builder's two size-limit envelopes.
 *
 * @return list<array<string, mixed>>
 */
function fzEnvelopes(): array
{
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.checkpoint.enabled' => true,
        'agenticgraph.tracing.capture.http' => true,
        'agenticgraph.tracing.capture.db' => true,
        'agenticgraph.tracing.capture.state' => 'full',
        'agenticgraph.tracing.capture.prompts' => true,
        'agenticgraph.tracing.capture.responses' => true,
        'agenticgraph.tracing.capture.tool_args' => 'summary',
        'agenticgraph.tracing.capture.tool_results' => 'summary',
        'agenticgraph.tracing.capture.http_urls' => 'summary',
        'agenticgraph.tracing.capture.db_sql' => 'summary',
    ]);
    // Console context: the file exporter writes during flush.
    app()->forgetInstance('request');
    Http::fake([
        'api.freeze.test/ok*' => Http::response(['ok' => true]),
        'api.freeze.test/fail' => Http::response(['error' => 'bad'], 500),
        'down.freeze.test/*' => Http::failedConnection(),
    ]);

    $exporter = new FileExporter(fzDir());
    app()->instance(TracerExporter::class, $exporter);
    fzForgetRun();
    $provider = app()->getProvider(AgenticGraphServiceProvider::class);
    (fn () => $this->registerTracing())->call($provider);

    $graph = GraphBuilder::create('fz-run-a')
        ->addNode('work', FzWorkNode::class)
        ->addNode('approve', FzApproval::class)
        ->addConditionalEdge('work', FzRouter::class)
        ->setEntryPoint('work')
        ->addExitPoint('approve')
        ->build();
    app(GraphRegistry::class)->register('fz-run-a', $graph);
    $state = app(GraphExecutor::class)->execute($graph, new State(['q' => 'x']));

    $exporter->reset();
    fzForgetRun();
    app(GraphExecutor::class)->resumeWithInput($state->getInterruptCheckpointId(), ['ok' => true]);

    $queued = $GLOBALS['fzQueued'];
    fzForgetRun();
    app()->instance(TraceContext::class, new TraceContext($queued['trace_id'], $queued['root_span_id'], links: [['trace_id' => $queued['trace_id'], 'span_id' => $queued['span_id']]]));
    $exporter->resumeTrace($queued['trace_id'], null, 'continued');
    $late = ['vector.store' => 'pgvector', 'vector.operation' => 'upsert'];
    Trace::span('vector.upsert', fn () => null, $late, 'vector');
    app()->forgetInstance(TraceContext::class);
    $exporter->flush($queued['trace_id']);

    config([
        'agenticgraph.tracing.capture.tool_args' => 'full',
        'agenticgraph.tracing.capture.tool_results' => 'full',
        'agenticgraph.tracing.capture.http_urls' => 'full',
        'agenticgraph.tracing.capture.db_sql' => 'full',
    ]);
    fzForgetRun();
    $failing = GraphBuilder::create('fz-run-b')->addNode('boom', FzBoomNode::class)->setEntryPoint('boom')->addExitPoint('boom')->build();
    fzSwallow(fn () => app(GraphExecutor::class)->execute($failing, new State(['q' => 'y'])));

    $files = glob(fzDir().'/*/*.json') ?: [];
    sort($files);
    $envelopes = array_map(fn (string $file): array => json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR), $files);

    $builder = new TracePayloadBuilder;
    $trace = ['id' => 'fz-size', 'status' => 'completed'];
    $span = fn (int $i, int $bytes): array => ['id' => "s{$i}", 'trace_id' => 'fz-size', 'span_type' => 'custom', 'attributes' => ['blob' => str_repeat('x', $bytes)]];
    $envelopes[] = $builder->build($trace, array_map(fn (int $i): array => $span($i, 12_000), range(1, 120)), []);
    $envelopes[] = $builder->build($trace, [$span(1, 11_000_000)], []);

    return $envelopes;
}

/**
 * @param  list<string>  $allowed
 * @param  list<string>  $problems
 */
function fzSubset(array $keys, array $allowed, string $where, array &$problems): void
{
    foreach (array_diff($keys, $allowed) as $key) {
        $problems[] = "{$where}: [{$key}] is not in the registry";
    }
}

beforeEach(function () {
    File::deleteDirectory(fzDir());
});

afterEach(function () {
    File::deleteDirectory(fzDir());
});

test('contract freeze guard: the schema string is agenticgraph.trace.v1.1 in the code and the registry', function () {
    expect(TracePayloadBuilder::SCHEMA_VERSION)->toBe('agenticgraph.trace.v1.1')
        ->and(fzRegistry()['schema_version'])->toBe('agenticgraph.trace.v1.1');
});

test('contract freeze guard: every emitted key and value is in the registry, and every registry key is emitted', function () {
    $registry = fzRegistry();
    $envelopes = fzEnvelopes();
    $problems = [];
    $seen = [];
    $see = function (string $group, array $keys) use (&$seen): void {
        $seen[$group] = array_unique(array_merge($seen[$group] ?? [], $keys));
    };
    $pattern = '/'.$registry['span']['timestamp_pattern'].'/';

    foreach ($envelopes as $envelope) {
        $keys = array_keys($envelope);
        $see('envelope', $keys);
        fzSubset($keys, [...$registry['envelope']['keys'], ...$registry['envelope']['optional']], 'envelope', $problems);
        if ($envelope['schema_version'] !== $registry['schema_version']) {
            $problems[] = 'schema_version: '.$envelope['schema_version'];
        }

        if (isset($envelope['_meta'])) {
            $meta = $envelope['_meta'];
            $see('_meta', array_keys($meta));
            $set = isset($meta['error_reason']) ? $registry['_meta']['payload_too_large'] : $registry['_meta']['truncated'];
            if (array_keys($meta) !== $set) {
                $problems[] = '_meta keys: '.json_encode(array_keys($meta));
            }
            fzSubset($meta['truncation_applied'], $registry['_meta']['truncation_applied'], '_meta.truncation_applied', $problems);
            fzSubset(array_keys($meta['dropped'] ?? []), $registry['_meta']['dropped'], '_meta.dropped', $problems);
            fzSubset(isset($meta['error_reason']) ? [$meta['error_reason']] : [], $registry['_meta']['error_reason'], '_meta.error_reason', $problems);
        }

        // The size-limit envelopes are built from a synthetic trace; only their _meta is checked.
        if (! isset($envelope['segment'])) {
            continue;
        }

        $trace = $envelope['trace'];
        $see('trace', array_keys($trace));
        fzSubset(array_keys($trace), [...$registry['trace']['always'], ...$registry['trace']['optional']], 'trace', $problems);
        fzSubset($registry['trace']['always'], array_keys($trace), 'trace (missing always key)', $problems);
        fzSubset([$trace['status']], $registry['trace']['status'], 'trace.status', $problems);
        if (array_keys($trace['resource']) !== $registry['trace']['resource']) {
            $problems[] = 'trace.resource keys: '.json_encode(array_keys($trace['resource']));
        }

        $segment = $envelope['segment'];
        if (array_keys($segment) !== $registry['segment']['keys']) {
            $problems[] = 'segment keys: '.json_encode(array_keys($segment));
        }
        fzSubset([$segment['kind']], $registry['segment']['kind'], 'segment.kind', $problems);
        if (preg_match('/'.$registry['segment']['id_pattern'].'/', $segment['id']) !== 1) {
            $problems[] = 'segment.id: '.$segment['id'];
        }

        foreach ($envelope['spans'] as $span) {
            $type = $span['span_type'];
            $spec = $registry['span_types'][$type] ?? null;
            $see('span_types', [$type]);
            if ($spec === null) {
                $problems[] = "span_type [{$type}] is not in the registry";

                continue;
            }
            fzSubset($registry['span']['common'], array_keys($span), "{$type} span (missing common key)", $problems);
            $see('span.optional', array_values(array_intersect(array_keys($span), $registry['span']['optional'])));
            $top = array_values(array_diff(array_keys($span), $registry['span']['common'], $registry['span']['optional'], ['attributes']));
            $see("{$type}.top", $top);
            fzSubset($top, $spec['top'], "{$type} span", $problems);
            fzSubset([$span['status']], $registry['span']['status'], "{$type}.status", $problems);
            fzSubset([$span['status_code']], $registry['span']['status_code'], "{$type}.status_code", $problems);
            fzSubset([$span['span_kind']], $registry['span']['span_kind'], "{$type}.span_kind", $problems);
            foreach (['started_at', 'ended_at'] as $stamp) {
                if (preg_match($pattern, (string) $span[$stamp]) !== 1) {
                    $problems[] = "{$type}.{$stamp}: {$span[$stamp]}";
                }
            }
            $nodeTypeOk = $spec['node_type'] === null ? is_string($span['node_type']) && $span['node_type'] !== '' : $span['node_type'] === $spec['node_type'];
            if (! $nodeTypeOk) {
                $problems[] = "{$type}.node_type: ".json_encode($span['node_type']);
            }

            $attributes = array_keys($span['attributes'] ?? []);
            $see("{$type}.attributes", $attributes);
            if (isset($spec['attributes_prefix'])) {
                foreach ($attributes as $attribute) {
                    if (! str_starts_with($attribute, $spec['attributes_prefix'])) {
                        $problems[] = "{$type} attribute [{$attribute}] lacks the {$spec['attributes_prefix']} prefix";
                    }
                }
            } elseif (! ($spec['attributes_open'] ?? false)) {
                fzSubset($attributes, $spec['attributes'], "{$type}.attributes", $problems);
            }
            if ($type === 'route') {
                fzSubset([$span['attributes']['route.kind']], $registry['route_kind'], 'route.kind', $problems);
            }
        }

        foreach ($envelope['llm_calls'] as $call) {
            $see('llm_calls', array_keys($call));
            fzSubset(array_keys($call), [...$registry['llm_calls']['always'], ...$registry['llm_calls']['optional']], 'llm_calls', $problems);
            fzSubset($registry['llm_calls']['always'], array_keys($call), 'llm_calls (missing always key)', $problems);
            fzSubset([$call['call_type']], $registry['llm_calls']['call_type'], 'llm_calls.call_type', $problems);
        }
    }

    // Registry to code: every registry key shows up in at least one real envelope.
    $expected = [
        'envelope' => [...$registry['envelope']['keys'], ...$registry['envelope']['optional']],
        'trace' => [...$registry['trace']['always'], ...$registry['trace']['optional']],
        '_meta' => array_unique([...$registry['_meta']['truncated'], ...$registry['_meta']['payload_too_large']]),
        'span_types' => array_keys($registry['span_types']),
        'span.optional' => $registry['span']['optional'],
        'llm_calls' => [...$registry['llm_calls']['always'], ...$registry['llm_calls']['optional']],
    ];
    foreach ($registry['span_types'] as $type => $spec) {
        $expected["{$type}.top"] = $spec['top'];
        $expected["{$type}.attributes"] = $spec['attributes'] ?? [];
    }
    foreach ($expected as $group => $keys) {
        foreach (array_diff($keys, $seen[$group] ?? []) as $key) {
            $problems[] = "{$group}: registry key [{$key}] was never emitted";
        }
    }

    expect($problems)->toBe([]);
});

test('contract freeze guard: every registry key and value is named in the prose of the wire doc', function () {
    preg_match_all('/`([^`\n]+)`/', fzProse(), $matches);
    $named = array_flip($matches[1]);
    // Registry structure, not wire names.
    $structural = ['envelope', 'keys', 'optional', 'always', 'common', 'top', 'attributes', 'attributes_prefix', 'attributes_open', 'id_pattern', 'timestamp_pattern', 'span_types', 'route_kind', 'resource', 'span', 'segment', 'trace', 'merged'];
    $names = [];
    $walk = function (array $node) use (&$walk, &$names): void {
        foreach ($node as $key => $value) {
            $names[] = $key;
            is_array($value) ? $walk($value) : $names[] = $value;
        }
    };
    $walk(fzRegistry());

    $missing = array_filter(
        array_unique($names),
        fn (mixed $name): bool => is_string($name) && preg_match('/^[A-Za-z_.]+$/', $name) === 1 && ! in_array($name, $structural, true) && ! isset($named[$name]),
    );

    expect(array_values($missing))->toBe([]);
});

test('contract freeze guard: the merger output matches the merged block, both directions', function () {
    $registry = fzRegistry()['merged'];
    $envelopes = fzEnvelopes();
    $byTrace = [];
    foreach ($envelopes as $envelope) {
        if (isset($envelope['segment'])) {
            $byTrace[$envelope['trace']['id']][] = $envelope;
        }
    }
    // The two size-limit envelopes as two segments of one trace: a merge with segment _meta,
    // error_reason and dropped from two segments.
    $sizeLimited = array_values(array_filter($envelopes, fn (array $envelope): bool => ! isset($envelope['segment'])));
    $byTrace['fz-size'] = array_map(fn (array $envelope, int $i): array => $envelope + ['segment' => [
        'id' => 'seg_000000000000000'.$i, 'kind' => $i === 0 ? 'initial' : 'continued', 'final' => $i === 1,
        'started_at' => "2026-09-27T10:00:0{$i}.000000+00:00", 'ended_at' => "2026-09-27T10:00:0{$i}.500000+00:00",
    ]], $sizeLimited, array_keys($sizeLimited));

    $problems = [];
    $seen = ['merged' => [], 'segments' => [], '_meta' => []];
    $merger = new TraceSegmentMerger;
    foreach ($byTrace as $traceId => $segments) {
        $merged = $merger->merge($segments);

        // Run A's queued work ends after the run: the trace ends with it, the root keeps the graph's end (wire v1.1).
        if ($traceId !== 'fz-size') {
            $root = array_values(array_filter($merged['spans'], fn (array $span): bool => $span['parent_id'] === null))[0];
            $sent = array_values(array_filter(array_merge(...array_column($segments, 'spans')), fn (array $span): bool => $span['id'] === $root['id']));
            if ($root['ended_at'] !== end($sent)['ended_at']) {
                $problems[] = "{$traceId} the merge moved the root end to {$root['ended_at']}";
            }
            if ($merged['trace']['ended_at'] !== max(array_column(array_column($segments, 'segment'), 'ended_at'))) {
                $problems[] = "{$traceId} trace ended_at is not the last segment end";
            }
        }
        $keys = array_keys($merged);
        $seen['merged'] = [...$seen['merged'], ...$keys];
        $expected = array_values(array_intersect([...$registry['keys'], ...$registry['optional']], $keys));
        if ($keys !== $expected) {
            $problems[] = "{$traceId} merged keys: ".json_encode($keys);
        }
        fzSubset($registry['keys'], $keys, "{$traceId} merged (missing key)", $problems);

        foreach ($merged['segments'] as $segment) {
            $seen['segments'] = [...$seen['segments'], ...array_keys($segment)];
            fzSubset(array_keys($segment), [...$registry['segments']['keys'], ...$registry['segments']['optional']], "{$traceId} segments[]", $problems);
            fzSubset($registry['segments']['keys'], array_keys($segment), "{$traceId} segments[] (missing key)", $problems);
        }
        if (isset($merged['_meta'])) {
            $seen['_meta'] = [...$seen['_meta'], ...array_keys($merged['_meta'])];
            fzSubset(array_keys($merged['_meta']), [...$registry['_meta']['always'], ...$registry['_meta']['optional']], "{$traceId} merged _meta", $problems);
            fzSubset($registry['_meta']['always'], array_keys($merged['_meta']), "{$traceId} merged _meta (missing key)", $problems);
        }
    }

    // Registry to code: every key of the merged block shows up in some merge.
    foreach ([
        'merged' => [...$registry['keys'], ...$registry['optional']],
        'segments' => [...$registry['segments']['keys'], ...$registry['segments']['optional']],
        '_meta' => [...$registry['_meta']['always'], ...$registry['_meta']['optional']],
    ] as $group => $keys) {
        foreach (array_diff($keys, $seen[$group]) as $key) {
            $problems[] = "merged {$group}: registry key [{$key}] was never emitted";
        }
    }

    expect(count($byTrace))->toBeGreaterThanOrEqual(3)
        ->and(max(array_map('count', $byTrace)))->toBeGreaterThanOrEqual(2)
        ->and($problems)->toBe([]);
});
