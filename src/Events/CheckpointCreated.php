<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a checkpoint is created.
 */
class CheckpointCreated implements ShouldQueue
{
    use SerializesModels;

    public function __construct(
        public readonly string $executionId,
        public readonly string $checkpointId,
        public readonly string $nodeName,
        public readonly \DateTimeImmutable $createdAt,
    ) {}
}
