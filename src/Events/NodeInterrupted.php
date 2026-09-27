<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

class NodeInterrupted implements ShouldQueue
{
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $stateSnapshot
     * @param  array<string, mixed>|null  $interruptPayload
     */
    public function __construct(
        public readonly string $executionId,
        public readonly string $nodeName,
        public readonly string $reason,
        public readonly string $checkpointId,
        public readonly array $stateSnapshot,
        public readonly \DateTimeImmutable $interruptedAt,
        public readonly ?array $interruptPayload = null,
        public readonly ?string $parentSpanId = null,
        public readonly ?string $graphName = null,
        public readonly ?string $rootSpanId = null,
    ) {}
}
