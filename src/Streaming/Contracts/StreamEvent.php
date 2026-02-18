<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Contracts;

interface StreamEvent
{
    public function streamId(): string;

    public function executionId(): string;

    public function nodeName(): ?string;

    public function spanId(): ?string;

    public function parentSpanId(): ?string;

    public function type(): string;

    public function seq(): int;

    public function nodeSeq(): ?int;

    public function occurredAt(): \DateTimeImmutable;

    public function payload(): mixed;
}
