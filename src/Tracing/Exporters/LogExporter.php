<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Exporters;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;

/**
 * OSS tracer exporter that writes structured trace payloads to the Laravel log.
 * No AgenticPulse account or API key required.
 */
final class LogExporter implements TracerExporter
{
    /** @var array<string, array<string, mixed>> */
    private array $traces = [];

    /** @var array<int, array<string, mixed>> */
    private array $spans = [];

    /** @var array<int, array<string, mixed>> */
    private array $llmCalls = [];

    /** @var array<string, Carbon> */
    private array $traceStarts = [];

    /** @var array<string, true> */
    private array $flushedTraceIds = [];

    private int $captureDepth = 0;

    public function isEnabled(): bool
    {
        return (bool) config('agenticgraph.tracing.enabled', false);
    }

    public function isCapturing(): bool
    {
        return $this->captureDepth > 0;
    }

    public function getTraceStartTime(string $traceId): ?Carbon
    {
        return $this->traceStarts[$traceId] ?? null;
    }

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

        $startTime = $startedAt ? Carbon::instance($startedAt) : now();
        $this->traceStarts[$traceId] = $startTime;

        $this->traces[$traceId] = [
            'id' => $traceId,
            'graph_name' => $graphName,
            'status' => 'running',
            'started_at' => $startTime->toIso8601String(),
            'initial_state_keys' => $initialStateKeys,
            'exporter' => 'log',
        ];

        if ($initialStateData !== null && $initialStateData !== []) {
            $this->traces[$traceId]['initial_state'] = $initialStateData;
        }
    }

    public function addSpan(array $span): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->whileCapturing(function () use ($span): void {
            $this->spans[] = $span;
        });
    }

    public function addLlmCall(array $call): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->whileCapturing(function () use ($call): void {
            $this->llmCalls[] = $call;
        });
    }

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
        $startedAt = $this->traceStarts[$traceId] ?? null;
        if ($startedAt) {
            $this->traces[$traceId]['ended_at'] = $startedAt
                ->copy()
                ->addMilliseconds((int) $durationMs)
                ->toIso8601String();
        } else {
            $this->traces[$traceId]['ended_at'] = now()->toIso8601String();
        }
        $this->traces[$traceId]['duration_ms'] = $durationMs;
        $this->traces[$traceId]['final_state_keys'] = $finalStateKeys;

        if ($finalStateData !== null && $finalStateData !== []) {
            $this->traces[$traceId]['final_state'] = $finalStateData;
        }

        $this->flush($traceId);
    }

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
        $startedAt = $this->traceStarts[$traceId] ?? null;
        if ($startedAt) {
            $this->traces[$traceId]['ended_at'] = $startedAt
                ->copy()
                ->addMilliseconds((int) $durationMs)
                ->toIso8601String();
        } else {
            $this->traces[$traceId]['ended_at'] = now()->toIso8601String();
        }
        $this->traces[$traceId]['duration_ms'] = $durationMs;
        $this->traces[$traceId]['error'] = [
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
        ];

        if ($finalStateData !== null && $finalStateData !== []) {
            $this->traces[$traceId]['final_state'] = $finalStateData;
        }

        $this->flush($traceId);
    }

    public function interruptTrace(string $traceId, string $reason): void
    {
        if (! $this->isEnabled() || ! isset($this->traces[$traceId])) {
            return;
        }

        $this->traces[$traceId]['status'] = 'interrupted';
        $this->traces[$traceId]['interrupt_reason'] = $reason;
        $this->traces[$traceId]['interrupted_at'] = now()->toIso8601String();

        $this->flush($traceId);
    }

    public function resumeTrace(string $traceId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if (isset($this->traces[$traceId])) {
            $this->traces[$traceId]['status'] = 'running';
            $this->traces[$traceId]['resumed_at'] = now()->toIso8601String();
            unset($this->traces[$traceId]['interrupt_reason']);
            unset($this->traces[$traceId]['interrupted_at']);
            unset($this->flushedTraceIds[$traceId]);
        }
    }

    public function flush(string $traceId): void
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

            return [
                'trace' => $this->traces[$traceId],
                'spans' => $localSpans,
                'llm_calls' => $localCalls,
            ];
        });

        if ((bool) config('agenticgraph.logging.enabled', true)) {
            Log::info('agenticgraph.trace', $payload);
        }

        $this->reset();
    }

    /**
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

    public function reset(): void
    {
        $this->traces = [];
        $this->spans = [];
        $this->llmCalls = [];
        $this->traceStarts = [];
        $this->flushedTraceIds = [];
        $this->captureDepth = 0;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function whileCapturing(callable $callback): mixed
    {
        $this->captureDepth++;

        try {
            return $callback();
        } finally {
            $this->captureDepth--;
        }
    }
}
