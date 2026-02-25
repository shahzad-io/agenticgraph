<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

class InterruptPending implements ShouldQueue
{
    use SerializesModels;

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $interruptId,
        public readonly string $executionId,
        public readonly string $graphName,
        public readonly string $nodeName,
        public readonly string $reason,
        public readonly string $checkpointId,
        public readonly ?array $payload,
        public readonly array $metadata,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
