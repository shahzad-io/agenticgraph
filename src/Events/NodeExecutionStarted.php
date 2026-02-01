<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Shahzad\AgenticGraph\Contracts\StateContract;

class NodeExecutionStarted implements ShouldQueue
{
    use SerializesModels;

    public function __construct(
        public readonly string $executionId,
        public readonly string $nodeName,
        public readonly StateContract $inputState,
        public readonly \DateTimeImmutable $startedAt,
        // New fields for tracing (backward compatible with defaults)
        public readonly ?string $spanId = null,
        public readonly ?string $parentSpanId = null,
    ) {}
}
