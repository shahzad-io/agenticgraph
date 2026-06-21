<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

final class ExecutionOptions
{
    public function __construct(
        public readonly ExecutionMode $mode = ExecutionMode::SYNC,
        public readonly StreamingMode $streaming = StreamingMode::NONE,
        public readonly bool $interrupts = true,
        public readonly ?int $estimatedDurationSeconds = null
    ) {}

    public function withStreaming(StreamingMode $streaming): self
    {
        return new self(
            mode: $this->mode,
            streaming: $streaming,
            interrupts: $this->interrupts,
            estimatedDurationSeconds: $this->estimatedDurationSeconds,
        );
    }
}
