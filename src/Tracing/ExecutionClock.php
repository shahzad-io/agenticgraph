<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

final class ExecutionClock
{
    private float $executionStartMicrotime;

    public function __construct(
        private readonly string $executionId,
        ?float $traceStartMicrotime = null
    ) {
        $this->executionStartMicrotime = $traceStartMicrotime ?? microtime(true);
    }

    public function getExecutionId(): string
    {
        return $this->executionId;
    }

    public function getTraceStartMicrotime(): float
    {
        return $this->executionStartMicrotime;
    }

    public function spanStartedAt(float $spanStartMicrotime): \DateTimeImmutable
    {
        return TraceTime::fromMicrotime($spanStartMicrotime);
    }

    public function spanEndedAt(float $spanStartMicrotime, float $durationMs): \DateTimeImmutable
    {
        return TraceTime::addMs($this->spanStartedAt($spanStartMicrotime), $durationMs);
    }

    public static function tryGet(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }
}
