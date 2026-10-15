<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

final class ResumeResult
{
    public function __construct(
        public readonly ExecutionHandle $handle,
        public readonly ?InterruptRecord $interrupt,
        public readonly bool $alreadyResolved = false,
        public readonly ?string $streamId = null,
        /** True when the interrupt expired (agenticgraph:interrupt:expire): nothing was dispatched and the execution is failed. */
        public readonly bool $expired = false,
    ) {}
}
