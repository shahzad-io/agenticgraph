<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Sinks;

use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
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
        $this->lines->push($this->format(
            (string) $event->seq(),
            $event->type(),
            $payload
        ));
    }

    public function close(StreamStatus $status, mixed $meta = null): void
    {
        $payload = ['status' => $status->value, 'meta' => $meta];
        $this->lines->push($this->format(null, 'stream_close', $payload));
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

    private function format(?string $id, string $event, mixed $data): string
    {
        $lines = '';
        if ($id !== null) {
            $lines .= 'id: '.$id."\n";
        }
        $lines .= 'event: '.$event."\n";
        $lines .= 'data: '.json_encode($data, JSON_UNESCAPED_SLASHES)."\n\n";

        return $lines;
    }
}
