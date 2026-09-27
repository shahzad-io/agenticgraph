<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Exporters;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\TracePayloadBuilder;
use Shahzad\AgenticGraph\Tracing\TraceTime;

/**
 * Shared lifecycle for exporters that send the JSON trace envelope (pulse, file, log).
 * Buffers spans in memory during execution and flushes once per segment (interrupt, complete, fail).
 * An interrupted trace is kept in the cache so a resume in another process continues it.
 */
abstract class BufferedTraceExporter implements TracerExporter
{
    /** @var array<string, array<string, mixed>> */
    private array $traces = [];

    /** @var array<int, array<string, mixed>> */
    private array $spans = [];

    /** @var array<int, array<string, mixed>> */
    private array $llmCalls = [];

    /** @var array<string, bool> */
    private array $sampledTraces = [];

    /** @var array<string, Carbon> */
    private array $traceStarts = [];

    /** @var array<string, true> */
    private array $flushedTraceIds = [];

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
    protected function whileCapturing(callable $callback): mixed
    {
        $this->captureDepth++;

        try {
            return $callback();
        } finally {
            $this->captureDepth--;
        }
    }

    /**
     * Start a new trace.
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

        if (isset($this->traces[$traceId])) {
            $this->traces[$traceId]['status'] = 'running';

            return;
        }

        $sampled = $this->shouldSampleTrace();
        $this->sampledTraces[$traceId] = $sampled;
        $startTime = $startedAt ? Carbon::instance($startedAt) : now();
        $this->traceStarts[$traceId] = $startTime;

        $this->traces[$traceId] = [
            'id' => $traceId,
            'graph_name' => $graphName,
            'status' => 'running',
            'started_at' => TraceTime::format($startTime),
            'initial_state_keys' => $initialStateKeys,
            'sampled' => $sampled,
            'resource' => [
                'service.name' => (string) config('agenticgraph.tracing.service.name', 'agenticgraph'),
                'service.version' => (string) config('agenticgraph.tracing.service.version', 'unknown'),
                'deployment.environment' => (string) config('agenticgraph.tracing.environment', 'local'),
            ],
            'instrumentation_scope' => (string) config('agenticgraph.tracing.instrumentation_scope', 'agenticgraph'),
        ];

        // Add initial state data if provided
        if ($initialStateData !== null && $initialStateData !== []) {
            $this->traces[$traceId]['initial_state'] = $initialStateData;
        }
    }

    public function getTraceStartTime(string $traceId): ?Carbon
    {
        return $this->traceStarts[$traceId] ?? null;
    }

    /**
     * @param  array<string, mixed>  $span
     */
    public function addSpan(array $span): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $traceId = $span['trace_id'] ?? null;
        if (! $this->isSampled($traceId)) {
            return;
        }

        $this->whileCapturing(function () use ($span): void {
            $this->spans[] = $span;
        });
    }

    /**
     * @param  array<string, mixed>  $call
     */
    public function addLlmCall(array $call): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $traceId = $call['trace_id'] ?? null;
        if (! $this->isSampled($traceId)) {
            return;
        }

        $this->whileCapturing(function () use ($call): void {
            $this->llmCalls[] = $call;
        });
    }

    /**
     * Complete a trace (success).
     *
     * @param  array<int, string>  $finalStateKeys
     * @param  array<string, mixed>|null  $finalStateData
     */
    public function completeTrace(
        string $traceId,
        array $finalStateKeys = [],
        float $durationMs = 0,
        ?array $finalStateData = null
    ): void {
        if (! $this->isEnabled() || ! isset($this->traces[$traceId]) || isset($this->flushedTraceIds[$traceId])) {
            return;
        }

        $this->traces[$traceId]['status'] = 'completed';
        $this->markTraceEnded($traceId, $durationMs);
        $this->traces[$traceId]['final_state_keys'] = $finalStateKeys;

        // Add final state data if provided
        if ($finalStateData !== null && $finalStateData !== []) {
            $this->traces[$traceId]['final_state'] = $finalStateData;
        }

        if (! $this->isSampled($traceId)) {
            $this->reset();

            return;
        }

        $this->forgetInterruptedBuffer($traceId);

        $this->flush($traceId);
    }

    /**
     * Fail a trace (error).
     *
     * @param  array<string, mixed>|null  $finalStateData
     */
    public function failTrace(
        string $traceId,
        \Throwable $exception,
        float $durationMs = 0,
        ?array $finalStateData = null
    ): void {
        if (! $this->isEnabled() || ! isset($this->traces[$traceId]) || isset($this->flushedTraceIds[$traceId])) {
            return;
        }

        $this->traces[$traceId]['status'] = 'failed';
        $this->markTraceEnded($traceId, $durationMs);
        $this->traces[$traceId]['error'] = [
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
        ];

        // Add final state data if provided (especially useful for error debugging)
        if ($finalStateData !== null && $finalStateData !== []) {
            $this->traces[$traceId]['final_state'] = $finalStateData;
        }

        if (! $this->isSampled($traceId)) {
            if (! $this->shouldAlwaysSampleOnError()) {
                $this->reset();

                return;
            }
        }

        $this->flush($traceId);
    }

    /**
     * Interrupt a trace (paused for human input).
     * Unlike fail/complete, this preserves the trace for continuation.
     */
    public function interruptTrace(string $traceId, string $reason): void
    {
        if (! $this->isEnabled() || ! isset($this->traces[$traceId])) {
            return;
        }

        $this->traces[$traceId]['status'] = 'interrupted';
        $this->traces[$traceId]['interrupt_reason'] = $reason;
        $interruptedAt = $this->markTraceEnded($traceId, 0.0);
        $this->traces[$traceId]['interrupted_at'] = TraceTime::format($interruptedAt);

        if (! $this->isSampled($traceId)) {
            $this->reset();

            return;
        }

        // Flush current progress so it's not lost during the interrupt
        $this->flush($traceId, persistForResume: true);
    }

    /**
     * Resume a previously interrupted trace.
     * Rehydrates trace state for continuation.
     */
    public function resumeTrace(string $traceId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if (! isset($this->traces[$traceId])) {
            $this->restoreInterruptedBuffer($traceId);
        }

        if (! isset($this->traces[$traceId])) {
            return;
        }

        $this->traces[$traceId]['status'] = 'running';
        $this->traces[$traceId]['resumed_at'] = TraceTime::format(TraceTime::now());
        unset($this->traces[$traceId]['interrupt_reason'], $this->traces[$traceId]['interrupted_at']);
        unset($this->flushedTraceIds[$traceId]);
    }

    /**
     * Build the envelope for one trace and send it.
     */
    public function flush(string $traceId, bool $persistForResume = false): void
    {
        if (! $this->isEnabled() || empty($this->traces[$traceId])) {
            $this->reset();

            return;
        }

        if (isset($this->flushedTraceIds[$traceId])) {
            return;
        }

        $this->flushedTraceIds[$traceId] = true;

        $payload = $this->whileCapturing(function () use ($traceId): array {
            $localSpans = array_values(array_filter(
                $this->spans,
                fn ($s) => ($s['trace_id'] ?? '') === $traceId
            ));
            $localCalls = array_values(array_filter(
                $this->llmCalls,
                fn ($c) => ($c['trace_id'] ?? '') === $traceId
            ));

            return $this->payloadBuilder->build($this->traces[$traceId], $localSpans, $localCalls);
        });

        $requestBound = app()->bound('request');
        $isHttpContext = $requestBound && (! app()->runningInConsole() || app()->runningUnitTests());

        if ($isHttpContext) {
            // In HTTP context, dispatch after response to avoid blocking
            dispatch(function () use ($payload): void {
                $this->whileCapturing(function () use ($payload): void {
                    $this->send($payload);
                });
            })->afterResponse();
        } else {
            // In queue/console context, execute synchronously
            $this->whileCapturing(function () use ($payload): void {
                $this->send($payload);
            });
        }

        if ($persistForResume) {
            $this->persistInterruptedBuffer($traceId);
        } else {
            $this->forgetInterruptedBuffer($traceId);
        }

        $this->reset();
    }

    private function bufferCacheKey(string $traceId): string
    {
        $version = (string) config('agenticgraph.cache.version', 'v1');

        return "agenticgraph:trace_buffer:{$version}:{$traceId}";
    }

    private function persistInterruptedBuffer(string $traceId): void
    {
        if (! isset($this->traces[$traceId])) {
            return;
        }

        $payload = [
            'trace' => $this->traces[$traceId],
            'spans' => array_values(array_filter(
                $this->spans,
                fn (array $span): bool => ($span['trace_id'] ?? '') === $traceId
            )),
            'llm_calls' => array_values(array_filter(
                $this->llmCalls,
                fn (array $call): bool => ($call['trace_id'] ?? '') === $traceId
            )),
            'trace_start' => isset($this->traceStarts[$traceId])
                ? TraceTime::format($this->traceStarts[$traceId])
                : null,
            'sampled' => $this->sampledTraces[$traceId] ?? false,
        ];

        $ttl = (int) config('agenticgraph.tracing.buffer.ttl_seconds', 3600);
        $this->bufferCache()->put($this->bufferCacheKey($traceId), $payload, $ttl);
    }

    private function restoreInterruptedBuffer(string $traceId): void
    {
        $payload = $this->bufferCache()->get($this->bufferCacheKey($traceId));
        if (! is_array($payload) || ! is_array($payload['trace'] ?? null)) {
            return;
        }

        $this->traces[$traceId] = $payload['trace'];

        foreach ($payload['spans'] ?? [] as $span) {
            if (is_array($span)) {
                $this->spans[] = $span;
            }
        }

        foreach ($payload['llm_calls'] ?? [] as $call) {
            if (is_array($call)) {
                $this->llmCalls[] = $call;
            }
        }

        if (is_string($payload['trace_start'] ?? null) && $payload['trace_start'] !== '') {
            $this->traceStarts[$traceId] = Carbon::parse($payload['trace_start']);
        }

        $this->sampledTraces[$traceId] = (bool) ($payload['sampled'] ?? true);
    }

    private function forgetInterruptedBuffer(string $traceId): void
    {
        $this->bufferCache()->forget($this->bufferCacheKey($traceId));
    }

    private function bufferCache(): Repository
    {
        return Cache::store(config('agenticgraph.tracing.buffer.store', null));
    }

    private function markTraceEnded(string $traceId, float $durationMs): \DateTimeImmutable
    {
        [$endedAt, $durationMs] = TraceTime::traceEnd($this->traceStarts[$traceId] ?? null, $durationMs);

        $this->traces[$traceId]['ended_at'] = TraceTime::format($endedAt);
        $this->traces[$traceId]['duration_ms'] = $durationMs;

        // active_ms sums the segments (start or resume, to interrupt or end), so human wait is
        // excluded. The running total rides in the trace buffer across an interrupt.
        $segmentStart = TraceTime::parse($this->traces[$traceId]['resumed_at'] ?? null)
            ?? $this->traceStarts[$traceId]
            ?? null;
        $segmentMs = $segmentStart ? max(TraceTime::diffMs($segmentStart, $endedAt), 0.0) : $durationMs;
        $this->traces[$traceId]['active_ms'] = (float) ($this->traces[$traceId]['active_ms'] ?? 0.0) + $segmentMs;

        return $endedAt;
    }

    /**
     * Reset the buffer.
     */
    public function reset(): void
    {
        $this->traces = [];
        $this->spans = [];
        $this->llmCalls = [];
        $this->sampledTraces = [];
        $this->traceStarts = [];
        $this->flushedTraceIds = [];
        $this->captureDepth = 0;
    }

    /**
     * Get the current buffer (for testing).
     *
     * @return array<string, mixed>
     */
    public function getBuffer(): array
    {
        return [
            'traces' => $this->traces,
            'spans' => $this->spans,
            'llm_calls' => $this->llmCalls,
        ];
    }

    private function shouldSampleTrace(): bool
    {
        $rate = (float) config('agenticgraph.tracing.sampling.rate', 1.0);
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return mt_rand() / mt_getrandmax() < $rate;
    }

    private function isSampled(?string $traceId): bool
    {
        if (! $traceId) {
            return false;
        }

        return $this->sampledTraces[$traceId] ?? true;
    }

    private function shouldAlwaysSampleOnError(): bool
    {
        return (bool) config('agenticgraph.tracing.sampling.always_on_error', true);
    }
}
