<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

class ToolCallCaptured implements ShouldQueue
{
    use SerializesModels;

    public function __construct(
        public readonly string $executionId,
        public readonly ?string $spanId,
        public readonly string $toolName,
        public readonly ?string $toolCallId,
        public readonly ?string $toolType,
        public readonly ?int $inputSize,
        public readonly ?int $outputSize,
        public readonly ?float $durationMs,
        public readonly \DateTimeImmutable $startedAt,
        public readonly ?string $toolProvider = null,
        public readonly ?string $toolRequestId = null,
        public readonly string $status = 'success',
        public readonly ?string $errorType = null,
        public readonly ?string $errorMessage = null,
    ) {}
}
