<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

final class StreamOptions
{
    public function __construct(
        public readonly string $delivery = 'sse',
        public readonly string $mode = 'all_nodes',
        public readonly bool $interrupts = true,
        public readonly bool $resume = true,
        public readonly ?string $resumeFromCheckpointId = null,
        public readonly ?int $resumeFromSeq = null,
        public readonly int $heartbeatInterval = 30,
        public readonly bool $storeEnabled = true,
        public readonly bool $streamPartialState = false,
        public readonly mixed $nodeFilter = null,
        public readonly mixed $eventTypeFilter = null
    ) {}
}
