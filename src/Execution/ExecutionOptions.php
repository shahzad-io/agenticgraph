<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

final class ExecutionOptions
{
    public function __construct(
        public ExecutionMode $mode = ExecutionMode::SYNC,
        public StreamingMode $streaming = StreamingMode::NONE,
        public bool $interrupts = true,
        public ?int $timeout = null,
        public ?int $estimatedDurationSeconds = null
    ) {}
}
