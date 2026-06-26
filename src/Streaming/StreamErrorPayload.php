<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

final class StreamErrorPayload implements \JsonSerializable
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

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'error_type' => $this->errorType,
            'message' => $this->message,
            'stage' => $this->stage,
            'event_type' => $this->eventType,
            'event_seq' => $this->eventSeq,
            'event_node_name' => $this->eventNodeName,
            'event_node_seq' => $this->eventNodeSeq,
        ];
    }
}
