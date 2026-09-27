<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tests\Unit\Tracing\MockPulseExporter;
use Shahzad\AgenticGraph\Tracing\Listeners\TracingListener;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceTime;

class CapturingPulseExporter extends PulseExporter
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    protected function sendIngestPayload(array $payload): void
    {
        $this->sent[] = $payload;
    }
}

function capturingPulseExporter(): CapturingPulseExporter
{
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.endpoint' => 'https://api.example.com',
        'agenticgraph.tracing.api_key' => 'test-key',
    ]);
    // Console context: flush sends synchronously.
    app()->forgetInstance('request');

    return new CapturingPulseExporter;
}

function microsOf(string $at): int
{
    return (int) (new DateTimeImmutable($at))->format('Uu');
}

it('formats in utc with microseconds', function () {
    expect(TraceTime::format(1782554400.123456))->toBe('2026-06-27T10:00:00.123456+00:00')
        ->and(TraceTime::format(new DateTimeImmutable('2026-06-27 12:00:00.000042', new DateTimeZone('Europe/Berlin'))))
        ->toBe('2026-06-27T10:00:00.000042+00:00')
        ->and(TraceTime::format(TraceTime::now()))->toMatch(TRACE_TIME_PATTERN);
});

it('shifts by negative and sub-millisecond amounts', function () {
    $at = TraceTime::fromMicrotime(1782554400.000100);

    expect(TraceTime::format(TraceTime::addMs($at, -1500.25)))->toBe('2026-06-27T09:59:58.499850+00:00')
        ->and(TraceTime::format(TraceTime::addMs($at, 0.001)))->toBe('2026-06-27T10:00:00.000101+00:00')
        ->and(TraceTime::diffMs($at, TraceTime::addMs($at, 2.5)))->toBe(2.5);
});

it('orders two spans that start in the same second', function () {
    $exporter = new MockPulseExporter;
    $listener = new TracingListener($exporter);

    foreach ([1782554400.100000, 1782554400.700000] as $i => $start) {
        $listener->onNodeCompleted(new NodeExecutionCompleted(
            executionId: 'exec_order',
            nodeName: "node_{$i}",
            inputState: new State([]),
            outputState: new State([]),
            duration: 0.05,
            metadata: [],
            spanId: "span_{$i}",
            startedAt: TraceTime::fromMicrotime($start),
        ));
    }

    [$first, $second] = $exporter->spans;

    expect($first['started_at'])->toBe('2026-06-27T10:00:00.100000+00:00')
        ->and($first['ended_at'])->toBe('2026-06-27T10:00:00.150000+00:00')
        ->and($second['started_at'])->toBe('2026-06-27T10:00:00.700000+00:00')
        ->and(microsOf($second['started_at']))->toBeGreaterThan(microsOf($first['started_at']));
});

it('derives started_at from ended_at without throwing and clamps negative durations', function () {
    Log::spy();
    $exporter = new MockPulseExporter;
    $listener = new TracingListener($exporter);
    $endedAt = TraceTime::fromMicrotime(1782554402.000000);

    $listener->onTokensUsed(new TokensUsed('llm', 1, 1, 2, executionId: 'exec_neg', spanId: 'span_a', durationMs: 1500.0, endedAt: $endedAt));
    $listener->onTokensUsed(new TokensUsed('llm', 1, 1, 2, executionId: 'exec_neg', spanId: 'span_b', durationMs: -20.0, endedAt: $endedAt));

    expect($exporter->spans[0]['started_at'])->toBe('2026-06-27T10:00:00.500000+00:00')
        ->and($exporter->spans[0]['ended_at'])->toBe('2026-06-27T10:00:02.000000+00:00')
        ->and($exporter->spans[1]['started_at'])->toBe('2026-06-27T10:00:02.000000+00:00')
        ->and($exporter->spans[1]['ended_at'])->toBe('2026-06-27T10:00:02.000000+00:00');
    Log::shouldHaveReceived('debug')->with('AgenticGraph tracing: negative span duration clamped to 0', ['duration_ms' => -20.0])->once();
});

it('falls back to a 2000 character capture limit', function () {
    config(['agenticgraph.tracing.capture.prompts' => true]);
    $capture = config('agenticgraph.tracing.capture');
    unset($capture['max_length']);
    config(['agenticgraph.tracing.capture' => $capture]);
    $exporter = new MockPulseExporter;
    $listener = new TracingListener($exporter);

    $listener->onTokensUsed(new TokensUsed('llm', 1, 1, 2, executionId: 'exec_len', prompt: str_repeat('word ', 600)));

    expect(strlen($exporter->llmCalls[0]['prompt']))->toBeLessThanOrEqual(2000);
});

it('sends microsecond trace times with wall duration when the caller passes zero', function () {
    $exporter = capturingPulseExporter();
    $start = microtime(true) - 0.25;
    $exporter->startTrace('exec_wall', 'graph', [], TraceTime::fromMicrotime($start));
    $exporter->addSpan([
        'id' => 'span_1',
        'trace_id' => 'exec_wall',
        'started_at' => TraceTime::format($start + 0.01),
        'ended_at' => TraceTime::format($start + 0.02),
        'duration_ms' => 999.0,
    ]);

    $exporter->completeTrace('exec_wall', [], 0.0);

    $trace = $exporter->sent[0]['trace'];
    $span = $exporter->sent[0]['spans'][0];

    expect($trace['started_at'])->toMatch(TRACE_TIME_PATTERN)
        ->and($trace['ended_at'])->toMatch(TRACE_TIME_PATTERN)
        ->and($trace['duration_ms'])->toBeGreaterThanOrEqual(250.0)
        ->and($trace['duration_ms'] * 1000)->toEqualWithDelta(microsOf($trace['ended_at']) - microsOf($trace['started_at']), 1.0)
        // Timestamps are formatted once: a span with both ends set is never rewritten.
        ->and($span['ended_at'])->toBe(TraceTime::format($start + 0.02));
});

it('keeps a measured trace duration and does not recompute it from span times', function () {
    $exporter = capturingPulseExporter();
    $start = 1782554400.100000;
    $exporter->startTrace('exec_kept', 'graph', [], TraceTime::fromMicrotime($start));
    $exporter->addSpan([
        'id' => 'span_late',
        'trace_id' => 'exec_kept',
        'started_at' => TraceTime::format($start + 1.0),
        'ended_at' => TraceTime::format($start + 3.0),
        'duration_ms' => 2000.0,
    ]);

    $exporter->completeTrace('exec_kept', [], 1234.5678);

    $trace = $exporter->sent[0]['trace'];

    expect($trace['started_at'])->toBe('2026-06-27T10:00:00.100000+00:00')
        ->and($trace['duration_ms'])->toBe(1234.5678)
        // The window still widens to cover the late span, but duration_ms stays raw.
        ->and($trace['ended_at'])->toBe('2026-06-27T10:00:03.100000+00:00');
});

it('sends interrupt time and duration and restores the trace start with microseconds', function () {
    config(['agenticgraph.tracing.buffer.store' => 'trace_buffer']);
    config(['cache.stores.trace_buffer' => ['driver' => 'array', 'serialize' => false]]);
    $exporter = capturingPulseExporter();
    $start = microtime(true) - 0.5;
    $exporter->startTrace('exec_pause', 'graph', [], TraceTime::fromMicrotime($start));

    $exporter->interruptTrace('exec_pause', 'approval');

    $trace = $exporter->sent[0]['trace'];
    $cacheKey = 'agenticgraph:trace_buffer:'.config('agenticgraph.cache.version', 'v1').':exec_pause';

    expect($trace['interrupted_at'])->toMatch(TRACE_TIME_PATTERN)
        ->and($trace['ended_at'])->toBe($trace['interrupted_at'])
        ->and($trace['duration_ms'])->toBeGreaterThanOrEqual(500.0)
        ->and(Cache::store('trace_buffer')->has($cacheKey))->toBeTrue()
        ->and(Cache::store()->has($cacheKey))->toBeFalse();

    $resumed = new CapturingPulseExporter;
    $resumed->resumeTrace('exec_pause');

    expect(TraceTime::format($resumed->getTraceStartTime('exec_pause')))->toBe(TraceTime::format($start));

    $resumed->completeTrace('exec_pause', [], 0.0);

    expect($resumed->sent[0]['trace']['started_at'])->toBe(TraceTime::format($start))
        ->and($resumed->sent[0]['trace']['duration_ms'])->toBeGreaterThan($trace['duration_ms'])
        ->and(Cache::store('trace_buffer')->has($cacheKey))->toBeFalse();
});
