<?php

declare(strict_types=1);

use Shahzad\AgenticGraph\Facades\Trace;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\Tracer;

/**
 * Test double that records spans.
 */
final class CapturingTracerExporter implements TracerExporter
{
    /** @var array<int, array<string, mixed>> */
    public array $spans = [];

    public bool $forcedEnabled = true;

    public function isEnabled(): bool
    {
        return $this->forcedEnabled;
    }

    public function isCapturing(): bool
    {
        return false;
    }

    public function getTraceStartTime(string $traceId): ?\Illuminate\Support\Carbon
    {
        return null;
    }

    public function startTrace(
        string $traceId,
        string $graphName,
        array $initialStateKeys = [],
        ?\DateTimeImmutable $startedAt = null,
        ?array $initialStateData = null
    ): void {}

    public function addSpan(array $span): void
    {
        $this->spans[] = $span;
    }

    public function addLlmCall(array $call): void {}

    public function completeTrace(
        string $traceId,
        array $finalStateKeys = [],
        float $durationMs = 0,
        ?array $finalStateData = null
    ): void {}

    public function failTrace(
        string $traceId,
        \Throwable $exception,
        float $durationMs = 0,
        ?array $finalStateData = null
    ): void {}

    public function interruptTrace(string $traceId, string $reason): void {}

    public function resumeTrace(string $traceId): void {}

    public function flush(string $traceId): void {}
}

beforeEach(function (): void {
    config(['agenticgraph.tracing.enabled' => true]);

    app()->forgetInstance(TraceContext::class);
    app()->forgetInstance(ExecutionClock::class);
    app()->forgetInstance(Tracer::class);
    app()->forgetInstance(TracerExporter::class);
});

function bindCapturingExporter(): CapturingTracerExporter
{
    $exporter = new CapturingTracerExporter;
    app()->instance(TracerExporter::class, $exporter);
    app()->singleton(Tracer::class, fn ($app) => new Tracer($app->make(TracerExporter::class)));

    return $exporter;
}

function bindActiveTraceContext(string $executionId = 'exec_test', string $rootSpanId = 'span_root'): TraceContext
{
    $context = new TraceContext($executionId, $rootSpanId);
    app()->instance(TraceContext::class, $context);

    return $context;
}

it('tryGet returns bound trace context and null when unbound', function (): void {
    expect(TraceContext::tryGet())->toBeNull();

    $context = bindActiveTraceContext('exec_try', 'span_try');

    expect(TraceContext::tryGet())->toBe($context);
});

it('creates a child span nested under the active node span', function (): void {
    $exporter = bindCapturingExporter();
    $context = bindActiveTraceContext('exec_nested', 'span_root');
    $nodeSpanId = $context->startSpan('demo-node');

    $result = Trace::span('test.op', fn (): string => 'done');

    $context->endSpan();

    expect($result)->toBe('done')
        ->and($exporter->spans)->toHaveCount(1);

    $span = $exporter->spans[0];
    expect($span['name'] ?? $span['node_name'] ?? null)->toBe('test.op')
        ->and($span['trace_id'])->toBe('exec_nested')
        ->and($span['parent_id'])->toBe($nodeSpanId)
        ->and($span['span_type'])->toBe('custom')
        ->and($span['status'])->toBe('success')
        ->and($span['status_code'])->toBe('OK');
});

it('is a no-op without a bound trace context', function (): void {
    $exporter = bindCapturingExporter();

    $result = Trace::span('test.op', fn (): int => 42);

    expect($result)->toBe(42)
        ->and($exporter->spans)->toBeEmpty();
});

it('is a no-op when tracing is disabled', function (): void {
    config(['agenticgraph.tracing.enabled' => false]);

    $exporter = bindCapturingExporter();
    bindActiveTraceContext('exec_disabled', 'span_root');

    $result = Trace::span('test.op', fn (): int => 42);

    expect($result)->toBe(42)
        ->and($exporter->spans)->toBeEmpty();
});

it('balances the span stack and exports a failed span when the callback throws', function (): void {
    $exporter = bindCapturingExporter();
    $context = bindActiveTraceContext('exec_throw', 'span_root');
    $nodeSpanId = $context->startSpan('demo-node');

    try {
        Trace::span('test.op', fn (): never => throw new \RuntimeException('boom'));
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toBe('boom');
    }

    expect($context->getSpanId())->toBe($nodeSpanId)
        ->and($exporter->spans)->toHaveCount(1);

    $span = $exporter->spans[0];
    expect($span['status'])->toBe('failed')
        ->and($span['status_code'])->toBe('ERROR')
        ->and($span['error']['message'] ?? null)->toBe('boom');

    $afterSpanId = null;
    Trace::span('after.throw', function () use ($context, &$afterSpanId): string {
        $afterSpanId = $context->getSpanId();

        return 'ok';
    });

    expect($afterSpanId)->not->toBeNull()
        ->and($exporter->spans)->toHaveCount(2)
        ->and($exporter->spans[1]['parent_id'])->toBe($nodeSpanId);
});

it('routes spans through the configured tracer exporter', function (): void {
    $exporter = bindCapturingExporter();
    bindActiveTraceContext('exec_route', 'span_root');

    Trace::span('routed.op', fn (): bool => true);

    expect($exporter->spans)->toHaveCount(1)
        ->and($exporter->spans[0]['node_name'])->toBe('routed.op');
});

it('nests grandchild spans via the stack', function (): void {
    $exporter = bindCapturingExporter();
    bindActiveTraceContext('exec_grand', 'span_root');

    Trace::span('outer', function () {
        return Trace::span('inner', fn (): string => 'nested');
    });

    expect($exporter->spans)->toHaveCount(2);

    $outer = collect($exporter->spans)->firstWhere('node_name', 'outer');
    $inner = collect($exporter->spans)->firstWhere('node_name', 'inner');

    expect($outer)->not->toBeNull()
        ->and($inner)->not->toBeNull()
        ->and($inner['parent_id'])->toBe($outer['id'])
        ->and($outer['parent_id'])->toBe('span_root');
});

it('unifies stack span id with exported span id', function (): void {
    $exporter = bindCapturingExporter();
    $context = bindActiveTraceContext('exec_unify', 'span_root');

    $activeSpanId = null;
    Trace::span('unified', function () use ($context, &$activeSpanId): void {
        $activeSpanId = $context->getSpanId();
    });

    expect($activeSpanId)->not->toBeNull()
        ->and($exporter->spans[0]['id'])->toBe($activeSpanId);
});

it('truncates long string attributes using capture max_length', function (): void {
    config(['agenticgraph.tracing.capture.max_length' => 50]);

    $exporter = bindCapturingExporter();
    bindActiveTraceContext('exec_trunc', 'span_root');

    Trace::span('trunc', fn (): bool => true, [
        'payload' => str_repeat('x', 100),
    ]);

    $attributes = $exporter->spans[0]['attributes'] ?? [];
    expect($attributes['payload'] ?? '')->toEndWith('[...truncated]')
        ->and(strlen((string) $attributes['payload']))->toBeLessThanOrEqual(50);
});

it('uses execution clock when bound for span timestamps', function (): void {
    $exporter = bindCapturingExporter();
    bindActiveTraceContext('exec_clock', 'span_root');

    $executionMicrotime = microtime(true) - 2.0;
    $clock = new ExecutionClock('exec_clock', $executionMicrotime);
    app()->instance(ExecutionClock::class, $clock);

    Trace::span('clocked', fn (): bool => true);

    $startedAt = new DateTimeImmutable($exporter->spans[0]['started_at']);
    $executionStart = $clock->spanStartedAt($executionMicrotime);

    expect($startedAt->getTimestamp())->toBeGreaterThanOrEqual($executionStart->getTimestamp());
});

it('resolves tracer from the container', function (): void {
    config(['agenticgraph.tracing.exporter' => 'log']);

    expect(app(Tracer::class))->toBeInstanceOf(Tracer::class);
});

it('does not call exporter when exporter is disabled but still balances stack', function (): void {
    $exporter = bindCapturingExporter();
    $exporter->forcedEnabled = false;

    $context = bindActiveTraceContext('exec_no_export', 'span_root');
    $parentBefore = $context->getSpanId();

    Trace::span('silent', fn (): string => 'ok');

    expect($context->getSpanId())->toBe($parentBefore)
        ->and($exporter->spans)->toBeEmpty();
});
