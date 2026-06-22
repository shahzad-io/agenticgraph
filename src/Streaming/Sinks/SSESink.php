<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Sinks;

use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamStatus;

final class SSESink implements StreamSinkInterface
{
    private mixed $lines;

    public function __construct()
    {
        $this->lines = new \SplDoublyLinkedList;
    }

    public function emit(StreamEvent $event): void
    {
        $envelope = StreamEventEnvelope::fromStreamEvent($event);
        $this->lines->push($envelope->formatSse());
    }

    public function close(StreamStatus $status, mixed $meta = null): void
    {
        $payload = StreamEventEnvelope::streamCloseWirePayload($status, $meta);
        $this->lines->push(StreamEventEnvelope::formatSseRaw(null, StreamEventEnvelope::TYPE_STREAM_CLOSE, $payload));
    }

    public function lines(): mixed
    {
        return $this->lines;
    }

    public function content(): string
    {
        $buffer = '';
        foreach ($this->lines as $line) {
            $buffer .= $line;
        }

        return $buffer;
    }
}
