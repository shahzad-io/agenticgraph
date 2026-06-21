<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

final class StreamOptions
{
    public function __construct(
        public readonly string $delivery = 'sse',
        public readonly bool $interrupts = true,
        public readonly ?string $resumeFromCheckpointId = null,
        public readonly ?int $resumeFromSeq = null,
        public readonly bool $storeEnabled = true,
        // Internal-for-now: read by WrappedStreamSink::shouldEmit(); no public setter.
        public readonly mixed $nodeFilter = null,
        // Internal-for-now: read by WrappedStreamSink::shouldEmit(); no public setter.
        public readonly mixed $eventTypeFilter = null
    ) {}
}
