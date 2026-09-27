<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Exporters;

use Illuminate\Support\Carbon;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;

/**
 * No-op tracer exporter for OSS users who want tracing instrumentation
 * enabled without emitting anywhere (zero overhead after isEnabled checks).
 */
final class NullExporter implements TracerExporter
{
    public function isEnabled(): bool
    {
        return (bool) config('agenticgraph.tracing.enabled', false);
    }

    public function isCapturing(): bool
    {
        return false;
    }

    public function getTraceStartTime(string $traceId): ?Carbon
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

    public function addSpan(array $span): void {}

    public function addLlmCall(array $call): void {}

    public function completeTrace(
        string $traceId,
        array $finalStateKeys = [],
        float $durationMs = 0,
        ?array $finalStateData = null,
        ?\DateTimeInterface $at = null
    ): void {}

    public function failTrace(
        string $traceId,
        \Throwable $exception,
        float $durationMs = 0,
        ?array $finalStateData = null,
        ?\DateTimeInterface $at = null
    ): void {}

    public function interruptTrace(string $traceId, string $reason, ?\DateTimeInterface $at = null): void {}

    public function resumeTrace(
        string $traceId,
        ?string $graphName = null,
        string $kind = 'resumed',
        ?\DateTimeInterface $at = null
    ): void {}

    public function flush(string $traceId): void {}
}
