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

    /**
     * A new stream id: `stream_` and 32 hex digits from random bytes. Not from time (guessable), and no dot, which a
     * Laravel channel parameter never matches.
     */
    public static function newId(): string
    {
        return 'stream_'.bin2hex(random_bytes(16));
    }
}
