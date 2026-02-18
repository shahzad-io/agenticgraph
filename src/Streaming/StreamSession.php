<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

final class StreamSession
{
    public function __construct(
        public readonly string $streamId,
        public readonly string $executionId,
        public readonly StreamOptions $options,
        public readonly int $startedAtUnix,
        public int $seq = 0
    ) {}
}
