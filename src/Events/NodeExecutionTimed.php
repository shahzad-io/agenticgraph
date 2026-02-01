<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

class NodeExecutionTimed implements ShouldQueue
{
    use SerializesModels;

    public function __construct(
        public readonly string $node,
        public readonly float $duration
    ) {}
}
