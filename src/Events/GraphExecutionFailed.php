<?php

namespace Shahzad\AgenticGraph\Events;

use Shahzad\AgenticGraph\Contracts\StateContract;

/**
 * Dispatched when a graph execution fails.
 */
class GraphExecutionFailed
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $graphName,
        public readonly StateContract $state,
        public readonly \Throwable $exception,
        public readonly float $durationMs,
    ) {}
}
