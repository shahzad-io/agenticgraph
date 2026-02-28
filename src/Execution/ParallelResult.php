<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Shahzad\AgenticGraph\Contracts\StateContract;

final class ParallelResult
{
    public function __construct(
        public readonly StateContract $state,
        public readonly string $nextNode,
        public readonly bool $wasBatched
    ) {}
}
