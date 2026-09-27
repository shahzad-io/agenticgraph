<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Contracts;

use Illuminate\Support\Carbon;

/**
 * Contract for exporting graph execution traces and spans.
 *
 * All tracing emit paths (TracingListener, HTTP/DB instrumentation, execution
 * lifecycle) depend on this interface, not on a concrete exporter implementation.
 */
interface TracerExporter
{
    public function isEnabled(): bool;

    /**
     * True while the exporter is capturing, buffering, or flushing trace data.
     * Instrumentation must not record operations triggered by tracing itself.
     */
    public function isCapturing(): bool;

    public function getTraceStartTime(string $traceId): ?Carbon;

    /**
     * @param  array<int, string>  $initialStateKeys
     * @param  array<string, mixed>|null  $initialStateData
     */
    public function startTrace(
        string $traceId,
        string $graphName,
        array $initialStateKeys = [],
        ?\DateTimeImmutable $startedAt = null,
        ?array $initialStateData = null
    ): void;

    /**
     * @param  array<string, mixed>  $span
     */
    public function addSpan(array $span): void;

    /**
     * @param  array<string, mixed>  $call
     */
    public function addLlmCall(array $call): void;

    /**
     * @param  array<int, string>  $finalStateKeys
     * @param  array<string, mixed>|null  $finalStateData
     */
    public function completeTrace(
        string $traceId,
        array $finalStateKeys = [],
        float $durationMs = 0,
        ?array $finalStateData = null,
        ?\DateTimeInterface $at = null
    ): void;

    /**
     * @param  array<string, mixed>|null  $finalStateData
     */
    public function failTrace(
        string $traceId,
        \Throwable $exception,
        float $durationMs = 0,
        ?array $finalStateData = null,
        ?\DateTimeInterface $at = null
    ): void;

    public function interruptTrace(string $traceId, string $reason, ?\DateTimeInterface $at = null): void;

    public function resumeTrace(
        string $traceId,
        ?string $graphName = null,
        string $kind = 'resumed',
        ?\DateTimeInterface $at = null
    ): void;

    public function flush(string $traceId): void;
}
