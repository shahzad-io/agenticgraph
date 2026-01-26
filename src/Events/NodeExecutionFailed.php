<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Shahzad\AgenticGraph\Contracts\StateContract;

/**
 * Dispatched when a node execution fails (before retry or final failure).
 */
class NodeExecutionFailed
{
    public function __construct(
        public readonly string $executionId,
        public readonly ?string $spanId,
        public readonly string $nodeName,
        public readonly StateContract $state,
        public readonly \Throwable $exception,
        public readonly float $durationMs,
        public readonly int $retryAttempt,
        public readonly bool $willRetry,
    ) {}
}
