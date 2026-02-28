<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Shahzad\AgenticGraph\Contracts\StateContract;

final class InterruptResult
{
    public function __construct(
        public readonly StateContract $state,
        public readonly string $checkpointId,
        public readonly InterruptRecord $record,
        public readonly bool $hasStreamContext
    ) {}
}
