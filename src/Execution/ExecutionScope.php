<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\TraceContext;

readonly class ExecutionScope
{
    public function __construct(
        public string $executionId,
        public TraceContext $traceContext,
        public ExecutionClock $clock,
        public ExecutionContext $context,
        public float $startTime,
        public \stdClass $bindings,
        public ExecutionOptions $options,
    ) {}

    public function options(): ExecutionOptions
    {
        return $this->options;
    }
}
