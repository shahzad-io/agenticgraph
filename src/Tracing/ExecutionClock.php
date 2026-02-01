<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

// use Illuminate\Support\Facades\Log;

final class ExecutionClock
{
    private \DateTimeImmutable $executionStart;

    private float $executionStartMicrotime;

    public function __construct(
        private readonly string $executionId,
        ?float $traceStartMicrotime = null
    ) {
        $this->executionStartMicrotime = $traceStartMicrotime ?? microtime(true);
        $this->executionStart = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $this->executionStartMicrotime)) ?: new \DateTimeImmutable;
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
        $offsetSeconds = $spanStartMicrotime - $this->executionStartMicrotime;

        // Use DateInterval instead of modify() to properly handle fractional seconds
        // PHP's modify() ignores decimal points, interpreting "0.082563" as "82563" seconds
        $result = $this->addSecondsToDateTime($this->executionStart, $offsetSeconds);

        // Debug logging to trace timestamp calculations
        // Log::debug('ExecutionClock::spanStartedAt', [
        //     'execution_id' => $this->executionId,
        //     'execution_start_microtime' => $this->executionStartMicrotime,
        //     'execution_start_datetime' => $this->executionStart->format('Y-m-d\TH:i:s.uP'),
        //     'span_start_microtime' => $spanStartMicrotime,
        //     'offset_seconds' => $offsetSeconds,
        //     'result_datetime' => $result->format('Y-m-d\TH:i:s.uP'),
        // ]);

        return $result;
    }

    public function spanEndedAt(float $spanStartMicrotime, float $durationMs): \DateTimeImmutable
    {
        $startedAt = $this->spanStartedAt($spanStartMicrotime);
        $durationSeconds = $durationMs / 1000;

        // Use DateInterval instead of modify() for proper fractional second handling
        $result = $this->addSecondsToDateTime($startedAt, $durationSeconds);

        // Log::debug('ExecutionClock::spanEndedAt', [
        //     'execution_id' => $this->executionId,
        //     'started_at' => $startedAt->format('Y-m-d\TH:i:s.uP'),
        //     'duration_ms' => $durationMs,
        //     'duration_seconds' => $durationSeconds,
        //     'result_datetime' => $result->format('Y-m-d\TH:i:s.uP'),
        // ]);

        return $result;
    }

    /**
     * Add seconds to a DateTimeImmutable using DateInterval to properly handle fractional seconds.
     * PHP's DateTime::modify() ignores decimal points in the offset string (e.g., "0.082563" becomes "82563").
     */
    private function addSecondsToDateTime(\DateTimeImmutable $dt, float $seconds): \DateTimeImmutable
    {
        $wholeSeconds = intval($seconds);
        $fractionalSeconds = $seconds - $wholeSeconds;

        // Create interval with whole seconds
        $interval = new \DateInterval("PT{$wholeSeconds}S");

        // Set fractional seconds using the 'f' property (PHP 7.1+)
        if ($fractionalSeconds > 0) {
            $interval->f = $fractionalSeconds;
        }

        return $dt->add($interval);
    }

    public static function tryGet(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }
}
