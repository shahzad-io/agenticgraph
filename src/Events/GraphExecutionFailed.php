<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Shahzad\AgenticGraph\Contracts\StateContract;

/**
 * Dispatched when a graph execution fails.
 */
class GraphExecutionFailed implements ShouldQueue
{
    use SerializesModels;

    public function __construct(
        public readonly string $executionId,
        public readonly string $graphName,
        public readonly StateContract $state,
        public readonly \Throwable $exception,
        public readonly float $durationMs,
    ) {}
}
