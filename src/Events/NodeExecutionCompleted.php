<?php

namespace Shahzad\AgenticGraph\Events;

use Shahzad\AgenticGraph\Contracts\StateContract;

class NodeExecutionCompleted
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $nodeName,
        public readonly StateContract $inputState,
        public readonly StateContract $outputState,
        public readonly float $duration,
        public readonly array $metadata,
        // New field for tracing (backward compatible with default)
        public readonly ?string $spanId = null,
    ) {}
}
