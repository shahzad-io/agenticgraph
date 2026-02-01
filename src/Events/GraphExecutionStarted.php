<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Shahzad\AgenticGraph\Contracts\StateContract;

class GraphExecutionStarted implements ShouldQueue
{
    use SerializesModels;

    public function __construct(
        public readonly string $executionId,
        public readonly string $graphName,
        public readonly StateContract $initialState,
        public readonly \DateTimeImmutable $startedAt,
        public readonly ?string $spanId = null,
    ) {}
}
