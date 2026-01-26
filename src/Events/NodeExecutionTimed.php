<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

class NodeExecutionTimed
{
    public function __construct(
        public readonly string $node,
        public readonly float $duration
    ) {}
}
