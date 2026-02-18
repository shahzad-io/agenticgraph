<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

final class StreamMetricsPayload
{
    public function __construct(
        public readonly int $eventsEmitted,
        public readonly int $bytesEmitted,
        public readonly int $errors,
        public readonly int $reconnects,
        public readonly int $replays,
        public readonly int $streamDurationMs,
        public readonly ?int $timeToFirstByteMs,
        public readonly int $lastSeq
    ) {}
}
