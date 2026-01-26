<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Shahzad\AgenticGraph\Contracts\StateContract;

class GraphExecutionStarted
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $graphName,
        public readonly StateContract $initialState,
        public readonly \DateTimeImmutable $startedAt
    ) {}
}
