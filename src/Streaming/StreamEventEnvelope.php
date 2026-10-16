<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;

/**
 * FROZEN PUBLIC WIRE CONTRACT (v1.1).
 *
 * The shapes below are stable public API for NPM clients, AgenticPulse, and other
 * stream consumers. Before the first public release, amendments are recorded in the
 * WIRE-CONTRACT.md version history (v1.1). After it, changing frozen fields, event
 * type strings, or delivery semantics is a breaking change requiring a major version bump.
 *
 * Canonical spec: docs/streaming/WIRE-CONTRACT.md
 *
 * FROZEN PUBLIC surface:
 * - Wire body: {@see self::FROZEN_WIRE_BODY_KEYS} (snake_case JSON; `type` is NOT in the body)
 * - Event `type` carried externally (SSE `event:` line / broadcast event name)
 * - Closed framework/lifecycle type strings ({@see StreamLifecycleEventTypes}, envelope constants)
 * - Prism content type set (version-pinned to Prism ^0.100.1, i.e. >=0.100.1 <0.101.0)
 * - Open author types via streamEvent() (any string + author payload)
 * - Broadcast channel convention and terminal contracts (stream_close / execution_terminal)
 * - SSE connection lifecycle and control events (`control` key, no id, no seq; see StreamController)
 * - Optional node_complete state capture shapes (config-gated)
 * - Observability fields: span_id, parent_span_id, node_seq (nullable semantics documented)
 *
 * SERIALIZED BUT NOT PROMISED (may change without a major bump):
 * - payload.stream on lifecycle events (framework telemetry)
 * - Raw Redis/database store row layout (differs from broadcast/SSE wire body)
 */
final class StreamEventEnvelope
{
    public const TYPE_EXECUTION_TERMINAL = 'execution_terminal';

    public const TYPE_STREAM_CLOSE = 'stream_close';

    public const TYPE_STREAM_ERROR = 'stream_error';

    /**
     * Frozen v1.0 wire-body field names emitted by {@see toWireArray()}.
     * `type` is intentionally excluded: it is carried by the transport event name.
     *
     * @var list<string>
     */
    public const FROZEN_WIRE_BODY_KEYS = [
        'stream_id',
        'execution_id',
        'node_name',
        'span_id',
        'parent_span_id',
        'seq',
        'node_seq',
        'occurred_at',
        'payload',
    ];

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
            'final_state' => self::clientFinalState($finalState),
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
        if (is_array($meta) && array_key_exists('final_state', $meta)) {
            $meta['final_state'] = self::clientFinalState($meta['final_state']);
        }

        return ['status' => $status->value, 'meta' => $meta];
    }

    /**
     * The only place that turns graph state into client-facing final_state
     * (execution_terminal, stream_close meta, the SSE replay_unavailable control event).
     *
     * Returns null unless streaming.final_state.expose is true. Accepts the storage
     * payload, a plain array, or a state object; returns plain state data, limited to
     * streaming.final_state.keys when that is a list.
     *
     * @return array<string, mixed>|null
     */
    public static function clientFinalState(mixed $finalState): ?array
    {
        if (! (bool) config('agenticgraph.streaming.final_state.expose', false)) {
            return null;
        }

        $data = match (true) {
            $finalState instanceof StateContract => $finalState->toArray(),
            is_array($finalState) => State::fromArray($finalState)->toArray(),
            default => null,
        };

        $keys = config('agenticgraph.streaming.final_state.keys', null);

        return $data !== null && is_array($keys)
            ? array_intersect_key($data, array_flip($keys))
            : $data;
    }
}
