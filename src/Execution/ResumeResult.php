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
    ) {}
}
