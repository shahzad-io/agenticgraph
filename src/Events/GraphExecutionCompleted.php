<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Shahzad\AgenticGraph\Contracts\StateContract;

class GraphExecutionCompleted implements ShouldQueue
{
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $statistics
     */
    public function __construct(
        public readonly string $executionId,
        public readonly string $graphName,
        public readonly StateContract $finalState,
        public readonly \DateTimeImmutable $completedAt,
        public readonly float $duration,
        public readonly array $statistics,
        public readonly ?string $spanId = null,
    ) {}
}
