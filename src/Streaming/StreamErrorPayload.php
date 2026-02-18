<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

final class StreamErrorPayload
{
    public function __construct(
        public readonly string $errorType,
        public readonly string $message,
        public readonly string $stage,
        public readonly ?string $eventType,
        public readonly ?int $eventSeq,
        public readonly ?string $eventNodeName,
        public readonly ?int $eventNodeSeq
    ) {}
}
