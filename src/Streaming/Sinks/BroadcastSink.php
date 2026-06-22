<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Sinks;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamStatus;

final class BroadcastSink implements StreamSinkInterface
{
    public function __construct(
        private readonly Broadcaster $broadcaster,
        private readonly string $streamId,
        private readonly string $channelPrefix = 'agenticgraph.stream.'
    ) {}

    public function emit(StreamEvent $event): void
    {
        $envelope = StreamEventEnvelope::fromStreamEvent($event);
        $this->broadcaster->broadcast(
            [$this->channelPrefix.$this->streamId],
            $envelope->type,
            $envelope->toWireArray()
        );
    }

    public function close(StreamStatus $status, mixed $meta = null): void
    {
        $payload = StreamEventEnvelope::streamCloseWirePayload($status, $meta);
        $this->broadcaster->broadcast(
            [$this->channelPrefix.$this->streamId],
            StreamEventEnvelope::TYPE_STREAM_CLOSE,
            $payload
        );
    }
}
