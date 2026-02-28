<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

final class ExecutionClockFactory
{
    public function create(string $executionId): ExecutionClock
    {
        return new ExecutionClock($executionId);
    }
}
