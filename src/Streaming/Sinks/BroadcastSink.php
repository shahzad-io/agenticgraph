<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Sinks;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
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
        $payload = [
            'stream_id' => $event->streamId(),
            'execution_id' => $event->executionId(),
            'node_name' => $event->nodeName(),
            'span_id' => $event->spanId(),
            'parent_span_id' => $event->parentSpanId(),
            'type' => $event->type(),
            'seq' => $event->seq(),
            'node_seq' => $event->nodeSeq(),
            'occurred_at' => $event->occurredAt()->format('c'),
            'payload' => $event->payload(),
        ];
        $this->broadcaster->broadcast(
            [$this->channelPrefix.$this->streamId],
            $event->type(),
            $payload
        );
    }

    public function close(StreamStatus $status, mixed $meta = null): void
    {
        $payload = ['status' => $status->value, 'meta' => $meta];
        $this->broadcaster->broadcast(
            [$this->channelPrefix.$this->streamId],
            'stream_close',
            $payload
        );
    }
}
