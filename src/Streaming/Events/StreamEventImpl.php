<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Events;

use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;

final class StreamEventImpl implements StreamEvent
{
    public function __construct(
        private readonly string $streamId,
        private readonly string $executionId,
        private readonly ?string $nodeName,
        private readonly ?string $spanId,
        private readonly ?string $parentSpanId,
        private readonly string $type,
        private readonly mixed $payload,
        private readonly int $seq,
        private readonly ?int $nodeSeq,
        private readonly \DateTimeImmutable $occurredAt
    ) {}

    public function streamId(): string
    {
        return $this->streamId;
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function nodeName(): ?string
    {
        return $this->nodeName;
    }

    public function spanId(): ?string
    {
        return $this->spanId;
    }

    public function parentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function seq(): int
    {
        return $this->seq;
    }

    public function nodeSeq(): ?int
    {
        return $this->nodeSeq;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function payload(): mixed
    {
        return $this->payload;
    }
}
