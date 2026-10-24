<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Shahzad\AgenticGraph\Contracts\StateContract;

/**
 * Dispatched when a node execution fails (before retry or final failure).
 */
class NodeExecutionFailed implements ShouldQueue
{
    use SerializesModels;

    public function __construct(
        public readonly string $executionId,
        public readonly ?string $spanId,
        public readonly string $nodeName,
        public readonly StateContract $state,
        public readonly \Throwable $exception,
        public readonly float $durationMs,
        public readonly int $retryAttempt,
        public readonly bool $willRetry,
        public readonly ?\DateTimeImmutable $startedAt = null,
        public readonly ?string $parentSpanId = null,
        public readonly ?string $nodeType = null,
        // Span id of attempt 1 of this step; shared by every attempt.
        public readonly ?string $attemptOf = null,
        // Where it failed: middleware_before, node or middleware_after.
        public readonly ?string $stage = null,
    ) {}
}
