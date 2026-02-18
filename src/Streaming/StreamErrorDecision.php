<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

final class StreamErrorDecision
{
    public function __construct(
        public readonly bool $fatal,
        public readonly StreamErrorPayload $payload
    ) {}
}
