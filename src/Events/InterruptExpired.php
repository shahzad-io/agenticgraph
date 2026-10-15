<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

/**
 * Fired once per interrupt that agenticgraph:interrupt:expire moved to expired. Its execution is failed by then.
 */
class InterruptExpired
{
    public function __construct(
        public readonly int $interruptId,
        public readonly string $executionId,
        public readonly string $graphName,
        public readonly string $nodeName,
        public readonly string $checkpointId,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
