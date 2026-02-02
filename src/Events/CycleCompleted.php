<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

/**
 * Event dispatched when a cycle completes in graph execution.
 * Used for observability and debugging of cyclic workflows.
 */
class CycleCompleted
{
    /**
     * @param  string  $executionId  The execution identifier
     * @param  string  $cycleHash  Unique hash of the cycle (e.g., 'A→B→A')
     * @param  array<int, string>  $cyclePath  Array of node names in the cycle
     * @param  int  $completionCount  How many times this cycle has completed
     * @param  int  $globalIterations  Total iterations at completion time
     * @param  \DateTimeImmutable  $completedAt  When the cycle completed
     * @param  string|null  $spanId  Optional parent span ID for tracing
     */
    public function __construct(
        public readonly string $executionId,
        public readonly string $cycleHash,
        public readonly array $cyclePath,
        public readonly int $completionCount,
        public readonly int $globalIterations,
        public readonly \DateTimeImmutable $completedAt,
        public readonly ?string $spanId = null,
    ) {}
}
