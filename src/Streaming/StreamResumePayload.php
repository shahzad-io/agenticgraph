<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

final readonly class StreamResumePayload
{
    public function __construct(
        public int $fromSeq,
        public int $resumedAtSeq,
        public mixed $gapEvents,
        public string $checkpointId
    ) {}
}
