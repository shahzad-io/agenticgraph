<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Shahzad\AgenticGraph\Contracts\StateContract;

final class InterruptStatus
{
    public function __construct(
        public readonly ExecutionStatus $status,
        public readonly ?InterruptRecord $interrupt,
        public readonly ?string $streamId,
        public readonly ?StateContract $latestState,
    ) {}
}
