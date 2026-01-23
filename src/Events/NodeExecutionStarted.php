<?php

namespace Shahzad\AgenticGraph\Events;

use Shahzad\AgenticGraph\Contracts\StateContract;

class NodeExecutionStarted
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $nodeName,
        public readonly StateContract $inputState,
        public readonly \DateTimeImmutable $startedAt
    ) {}
}
