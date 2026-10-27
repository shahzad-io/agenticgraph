<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Exporters;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Tracing\CaptureDepth;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\TracePayloadBuilder;
use Shahzad\AgenticGraph\Tracing\TraceTime;

/**
 * Shared lifecycle for exporters that send a trace as segments (pulse, file, log, otlp).
 * A segment is what one process produced for one trace between two boundaries (start, resume,
 * or job start, to interrupt, hand-off, complete, or fail). Each segment carries only its own
 * spans and LLM calls; the server (or TraceSegmentMerger) merges segments by trace id.
 * State is kept per trace, so sending one trace never touches another.
 */
abstract class BufferedTraceExporter implements TracerExporter
{
    /**
     * Open segments by trace id.
     *
     * @var array<string, array{trace: array<string, mixed>, segment: array<string, mixed>, start: \DateTimeImmutable, spans: list<array<string, mixed>>, llm_calls: list<array<string, mixed>>, implicit?: bool, after_commit?: bool, annotations?: array<string, mixed>}>
     */
    private array $open = [];

    protected int $captureDepth = 0;

    public function __construct(
        private readonly TracePayloadBuilder $payloadBuilder = new TracePayloadBuilder,
    ) {}

    abstract public function isEnabled(): bool;

    /**
     * Deliver one built envelope. Must not throw into the user's application.
     *
     * @param  array<string, mixed>  $payload
     */
    abstract protected function send(array $payload): void;

    /**
     * @param  array<string, mixed>  $trace
     * @param  array<string, mixed>  $segment
     * @param  array<int, array<string, mixed>>  $spans
     * @param  array<int, array<string, mixed>>  $llmCalls
     * @return array<string, mixed>
     */
    protected function buildPayload(array $trace, array $segment, array $spans, array $llmCalls): array
    {
        return $this->payloadBuilder->build($trace, $spans, $llmCalls, $segment);
    }

    /**
     * True while the exporter is capturing, buffering, or flushing trace data.
     * Instrumentation must not record operations triggered by tracing itself.
     */
    public function isCapturing(): bool
    {
        return $this->captureDepth > 0;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function whileCapturing(callable $callback): mixed
    {
        $this->captureDepth++;

        try {
            return $callback();
        } finally {
            $this->captureDepth--;
        }
    }

    /**
     * Open the initial segment of a trace.
     *
     * @param  array<int, string>  $initialStateKeys
     * @param  array<string, mixed>|null  $initialStateData
     */
    public function startTrace(
        string $traceId,
        string $graphName,
        array $initialStateKeys = [],
        ?\DateTimeImmutable $startedAt = null,
        ?array $initialStateData = null
    ): void {
        if (! $this->isEnabled()) {
            return;
        }

        // Data that arrived before the start opened an implicit continued segment in this
        // process. That segment is the trace start: reopen it as initial and keep its items.
        $early = $this->open[$traceId] ?? null;
        if ($early !== null && ! ($early['implicit'] ?? false)) {
            return;
        }

        if ($early !== null && $startedAt !== null && $early['start'] < $startedAt) {
            $startedAt = $early['start'];
        }
        $this->openSegment($traceId, $graphName, 'initial', $startedAt ?? $early['start'] ?? null);
        if ($early !== null) {
            $this->open[$traceId]['spans'] = $early['spans'];
            $this->open[$traceId]['llm_calls'] = $early['llm_calls'];
        }
        $this->open[$traceId]['trace']['started_at'] = $this->open[$traceId]['segment']['started_at'];
        $this->open[$traceId]['trace']['initial_state_keys'] = $initialStateKeys;

        if ($initialStateData !== null && $initialStateData !== []) {
            $this->open[$traceId]['trace']['initial_state'] = $initialStateData;
        }
    }

    /**
     * Start of the open segment. For the initial segment this is the trace start.
     */
    public function getTraceStartTime(string $traceId): ?Carbon
    {
        return isset($this->open[$traceId]) ? Carbon::instance($this->open[$traceId]['start']) : null;
    }

    /**
     * @param  array<string, mixed>  $span
     */
    public function addSpan(array $span): void
    {
        $this->collect('spans', $span);
    }

    /**
     * @param  array<string, mixed>  $call
     */
    public function addLlmCall(array $call): void
    {
        $this->collect('llm_calls', $call);
    }

    /**
     * Complete a trace (success). Sends the final segment.
     *
     * @param  array<int, string>  $finalStateKeys
     * @param  array<string, mixed>|null  $finalStateData
     */
    public function completeTrace(
        string $traceId,
        array $finalStateKeys = [],
        float $durationMs = 0,
        ?array $finalStateData = null,
        ?\DateTimeInterface $at = null
    ): void {
        if (! $this->isEnabled() || ! isset($this->open[$traceId])) {
            return;
        }

        $this->endSegment($traceId, 'completed', $at, $durationMs, final: true);
        $this->open[$traceId]['trace']['final_state_keys'] = $finalStateKeys;

        if ($finalStateData !== null && $finalStateData !== []) {
            $this->open[$traceId]['trace']['final_state'] = $finalStateData;
        }

        $this->isSampled($traceId) ? $this->sendSegment($traceId) : $this->forget($traceId);
    }

    /**
     * Fail a trace (error). Sends the final segment.
     *
     * @param  array<string, mixed>|null  $finalStateData
     */
    public function failTrace(
        string $traceId,
        \Throwable $exception,
        float $durationMs = 0,
        ?array $finalStateData = null,
        ?\DateTimeInterface $at = null
    ): void {
        if (! $this->isEnabled() || ! isset($this->open[$traceId])) {
            return;
        }

        $this->endSegment($traceId, 'failed', $at, $durationMs, final: true);
        $this->open[$traceId]['trace']['error'] = CaptureDepth::error(get_class($exception), $exception->getMessage());

        if ($finalStateData !== null && $finalStateData !== []) {
            $this->open[$traceId]['trace']['final_state'] = $finalStateData;
        }

        $this->isSampled($traceId) || $this->shouldAlwaysSampleOnError()
            ? $this->sendSegment($traceId)
            : $this->forget($traceId);
    }

    /**
     * Interrupt a trace (paused for human input). Sends the segment; the resume opens a new one.
     */
    public function interruptTrace(string $traceId, string $reason, ?\DateTimeInterface $at = null, ?\DateTimeInterface $expiresAt = null): void
    {
        if (! $this->isEnabled() || ! isset($this->open[$traceId])) {
            return;
        }

        $interruptedAt = $this->endSegment($traceId, 'interrupted', $at, 0.0, final: false);
        $this->open[$traceId]['trace']['interrupt_reason'] = $reason;
        $this->open[$traceId]['trace']['interrupted_at'] = TraceTime::format($interruptedAt);
        if ($expiresAt !== null) {
            $this->open[$traceId]['trace']['interrupt_expires_at'] = TraceTime::format($expiresAt);
        }

        $this->isSampled($traceId) ? $this->sendSegment($traceId) : $this->forget($traceId);
    }

    /**
     * Open a segment for a trace started elsewhere: kind "resumed" after an interrupt,
     * "continued" for queue jobs. A trace that is already open keeps its segment.
     */
    public function resumeTrace(
        string $traceId,
        ?string $graphName = null,
        string $kind = 'resumed',
        ?\DateTimeInterface $at = null
    ): void {
        if (! $this->isEnabled() || isset($this->open[$traceId])) {
            return;
        }

        $this->openSegment($traceId, $graphName, $kind, $at);

        if ($kind === 'resumed') {
            $this->open[$traceId]['trace']['resumed_at'] = $this->open[$traceId]['segment']['started_at'];
        }
    }

    /**
     * Set the trace-level annotations of the open segment; sent as the last key of its trace record.
     *
     * @param  array<string, mixed>  $annotations
     */
    public function annotateTrace(string $traceId, array $annotations): void
    {
        if (isset($this->open[$traceId])) {
            $this->open[$traceId]['annotations'] = $annotations;
        }
    }

    /**
     * Send the open segment of this trace only once the current database transaction commits;
     * a rollback drops it. Without an open transaction it is sent at once.
     */
    public function sendAfterCommit(string $traceId): void
    {
        if (isset($this->open[$traceId])) {
            $this->open[$traceId]['after_commit'] = true;
        }
    }

    /**
     * Send the open segment as it is (not final) and forget it: a queue hand-off, the end of a
     * batch job, or an execution scope that exits while the trace is still running.
     */
    public function flush(string $traceId): void
    {
        if (! $this->isEnabled() || ! isset($this->open[$traceId])) {
            return;
        }

        $this->isSampled($traceId) ? $this->sendSegment($traceId) : $this->forget($traceId);
    }

    /**
     * Clear every open trace. For tests; the lifecycle only ever forgets the trace it sent.
     */
    public function reset(): void
    {
        $this->open = [];
        $this->captureDepth = 0;
    }

    /**
     * Get the open segments (for testing).
     *
     * @return array{traces: array<string, array<string, mixed>>, spans: list<array<string, mixed>>, llm_calls: list<array<string, mixed>>}
     */
    public function getBuffer(): array
    {
        return [
            'traces' => array_map(fn (array $open): array => $open['trace'], $this->open),
            'spans' => array_merge([], ...array_column($this->open, 'spans')),
            'llm_calls' => array_merge([], ...array_column($this->open, 'llm_calls')),
        ];
    }

    /**
     * @param  'spans'|'llm_calls'  $bucket
     * @param  array<string, mixed>  $item
     */
    private function collect(string $bucket, array $item): void
    {
        $traceId = $item['trace_id'] ?? null;
        if (! $this->isEnabled() || ! is_string($traceId) || ! $this->isSampled($traceId)) {
            return;
        }

        $this->whileCapturing(function () use ($traceId, $bucket, $item): void {
            if (! isset($this->open[$traceId])) {
                // Data for a trace with no open segment (for example after its segment was sent)
                // starts a continued segment, so nothing is dropped.
                if ((bool) config('agenticgraph.logging.enabled', true)) {
                    Log::debug('AgenticGraph tracing: data for a trace with no open segment', ['trace_id' => $traceId]);
                }
                $this->openSegment($traceId, null, 'continued', null);
                $this->open[$traceId]['implicit'] = true;
            }

            $this->open[$traceId][$bucket][] = $item;
        });
    }

    private function openSegment(string $traceId, ?string $graphName, string $kind, ?\DateTimeInterface $at): void
    {
        $start = $at ? \DateTimeImmutable::createFromInterface($at) : TraceTime::now();
        $trace = ['id' => $traceId];
        if ($graphName !== null) {
            $trace['graph_name'] = $graphName;
        }

        $this->open[$traceId] = [
            'trace' => $trace + [
                'status' => 'running',
                'sampled' => $this->isSampled($traceId),
                'resource' => [
                    'service.name' => (string) config('agenticgraph.tracing.service.name', 'agenticgraph'),
                    'service.version' => (string) config('agenticgraph.tracing.service.version', 'unknown'),
                    'deployment.environment.name' => (string) config('agenticgraph.tracing.environment', 'local'),
                ],
                'instrumentation_scope' => (string) config('agenticgraph.tracing.instrumentation_scope', 'agenticgraph'),
            ],
            'segment' => [
                'id' => 'seg_'.bin2hex(random_bytes(8)),
                'kind' => $kind,
                'final' => false,
                'started_at' => TraceTime::format($start),
            ],
            'start' => $start,
            'spans' => [],
            'llm_calls' => [],
        ];
    }

    /**
     * Close the open segment at one instant: $at when the caller has it (the same
     * value as the root span end), otherwise start + measured duration, otherwise now.
     */
    private function endSegment(string $traceId, string $status, ?\DateTimeInterface $at, float $durationMs, bool $final): \DateTimeImmutable
    {
        $start = $this->open[$traceId]['start'];
        [$endedAt, $windowMs] = $at
            ? [\DateTimeImmutable::createFromInterface($at), max(TraceTime::diffMs($start, $at), 0.0)]
            : TraceTime::traceEnd($start, $durationMs);

        $this->open[$traceId]['trace']['status'] = $status;
        $this->open[$traceId]['segment']['final'] = $final;
        $this->open[$traceId]['segment']['ended_at'] = TraceTime::format($endedAt);

        if ($final) {
            $this->open[$traceId]['trace']['ended_at'] = TraceTime::format($endedAt);

            // A single-segment trace carries its own totals; otherwise the merger computes them.
            if ($this->open[$traceId]['segment']['kind'] === 'initial') {
                $this->open[$traceId]['trace']['duration_ms'] = $windowMs;
                $this->open[$traceId]['trace']['active_ms'] = $windowMs;
            }
        }

        return $endedAt;
    }

    private function sendSegment(string $traceId): void
    {
        $open = $this->open[$traceId];
        $this->forget($traceId);
        $open['segment']['ended_at'] ??= TraceTime::format(TraceTime::now());

        $payload = $this->whileCapturing(
            fn (): array => $this->buildPayload(
                $open['trace'] + (isset($open['annotations']) ? ['annotations' => $open['annotations']] : []),
                $open['segment'],
                $open['spans'],
                $open['llm_calls'],
            )
        );

        if ($open['after_commit'] ?? false) {
            DB::afterCommit(fn () => $this->whileCapturing(fn () => $this->send($payload)));

            return;
        }

        if (app()->bound('request') && ! app()->runningInConsole()) {
            // In HTTP context, send after the response to avoid blocking
            dispatch(function () use ($payload): void {
                $this->whileCapturing(function () use ($payload): void {
                    $this->send($payload);
                });
            })->afterResponse();

            return;
        }

        // In queue/console context, send synchronously
        $this->whileCapturing(function () use ($payload): void {
            $this->send($payload);
        });
    }

    private function forget(string $traceId): void
    {
        unset($this->open[$traceId]);
    }

    /**
     * Deterministic per trace id, so every process that sends a segment of the trace agrees.
     */
    private function isSampled(string $traceId): bool
    {
        $rate = (float) config('agenticgraph.tracing.sampling.rate', 1.0);
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0 || $traceId === '') {
            return false;
        }

        return crc32($traceId) / 0xFFFFFFFF < $rate;
    }

    private function shouldAlwaysSampleOnError(): bool
    {
        return (bool) config('agenticgraph.tracing.sampling.always_on_error', true);
    }
}
