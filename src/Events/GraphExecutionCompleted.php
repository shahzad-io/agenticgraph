<?php

namespace Shahzad\AgenticGraph\Events;

use Shahzad\AgenticGraph\Contracts\StateContract;

class GraphExecutionCompleted
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $graphName,
        public readonly StateContract $finalState,
        public readonly \DateTimeImmutable $completedAt,
        public readonly float $duration,
        public readonly array $statistics
    ) {}
}
