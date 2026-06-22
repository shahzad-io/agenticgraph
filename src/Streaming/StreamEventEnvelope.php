<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;

/**
 * Internal wire-serialization contract for stream events (not frozen public API).
 *
 * Canonical JSON body fields (type is owned once — carried by SSE event name / broadcast event name):
 * - stream_id, execution_id, node_name, span_id, parent_span_id
 * - seq, node_seq, occurred_at
 * - payload (opaque; lifecycle events may nest payload.stream = {seq, node_seq, event_type, bytes})
 *
 * Store terminal events use type {@see self::TYPE_EXECUTION_TERMINAL} with payload:
 * - status: completed|interrupted|failed
 * - final_state: array (unconditional when provided at close)
 * - meta: close metadata from the transport path
 */
final class StreamEventEnvelope
{
    public const TYPE_EXECUTION_TERMINAL = 'execution_terminal';

    public const TYPE_STREAM_CLOSE = 'stream_close';

    public const TYPE_STREAM_ERROR = 'stream_error';

    public function __construct(
        public readonly string $type,
        public readonly string $streamId,
        public readonly string $executionId,
        public readonly ?string $nodeName,
        public readonly ?string $spanId,
        public readonly ?string $parentSpanId,
        public readonly int $seq,
        public readonly ?int $nodeSeq,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly mixed $payload,
    ) {}

    public static function fromStreamEvent(StreamEvent $event): self
    {
        return new self(
            type: $event->type(),
            streamId: $event->streamId(),
            executionId: $event->executionId(),
            nodeName: $event->nodeName(),
            spanId: $event->spanId(),
            parentSpanId: $event->parentSpanId(),
            seq: $event->seq(),
            nodeSeq: $event->nodeSeq(),
            occurredAt: $event->occurredAt(),
            payload: $event->payload(),
        );
    }

    public static function executionTerminal(
        StreamSession $session,
        StreamTerminalStatus $status,
        mixed $finalState,
        mixed $meta = null
    ): self {
        $seq = ++$session->seq;
        $payload = [
            'status' => $status->value,
            'final_state' => $finalState,
        ];
        if ($meta !== null && $meta !== []) {
            $payload['meta'] = $meta;
        }

        return new self(
            type: self::TYPE_EXECUTION_TERMINAL,
            streamId: $session->streamId,
            executionId: $session->executionId,
            nodeName: null,
            spanId: null,
            parentSpanId: null,
            seq: $seq,
            nodeSeq: null,
            occurredAt: new \DateTimeImmutable,
            payload: $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toWireArray(): array
    {
        return [
            'stream_id' => $this->streamId,
            'execution_id' => $this->executionId,
            'node_name' => $this->nodeName,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'seq' => $this->seq,
            'node_seq' => $this->nodeSeq,
            'occurred_at' => $this->occurredAt->format('c'),
            'payload' => $this->payload,
        ];
    }

    public function toStreamEvent(): StreamEventImpl
    {
        return new StreamEventImpl(
            $this->streamId,
            $this->executionId,
            $this->nodeName,
            $this->spanId,
            $this->parentSpanId,
            $this->type,
            $this->payload,
            $this->seq,
            $this->nodeSeq,
            $this->occurredAt
        );
    }

    public function formatSse(?string $id = null): string
    {
        $resolvedId = $id ?? (string) $this->seq;

        return self::formatSseRaw($resolvedId, $this->type, $this->toWireArray());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function formatSseRaw(?string $id, string $eventType, array $data): string
    {
        $lines = '';
        if ($id !== null) {
            $lines .= 'id: '.$id."\n";
        }
        $lines .= 'event: '.$eventType."\n";
        $lines .= 'data: '.json_encode($data, JSON_UNESCAPED_SLASHES)."\n\n";

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    public static function streamCloseWirePayload(StreamStatus $status, mixed $meta = null): array
    {
        return ['status' => $status->value, 'meta' => $meta];
    }
}
