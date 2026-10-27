<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

class NodeResumed implements ShouldQueue
{
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $stateSnapshot
     */
    public function __construct(
        public readonly string $executionId,
        public readonly string $nodeName,
        public readonly string $checkpointId,
        public readonly array $stateSnapshot,
        public readonly \DateTimeImmutable $resumedAt,
        public readonly ?string $parentSpanId = null,
        public readonly ?string $graphName = null,
    ) {}
}
