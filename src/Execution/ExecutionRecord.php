<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

final class ExecutionRecord
{
    public function __construct(
        public string $executionId,
        public string $graphName,
        public ExecutionStatus $status,
        public mixed $initialState = null,
        public mixed $finalState = null,
        public ?string $streamId = null,
        public ?ExecutionMode $mode = null,
        public ?StreamingMode $streamingMode = null,
        public ?ExecutionOptions $executionOptions = null,
        public ?\DateTimeImmutable $interruptedAt = null,
        public ?\DateTimeImmutable $completedAt = null,
        public ?string $error = null
    ) {}
}
