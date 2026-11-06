<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Tracing\CaptureDepth;

/**
 * FROZEN PUBLIC WIRE CONTRACT (v1.1).
 *
 * The shapes below are stable public API for NPM clients, AgenticPulse, and other
 * stream consumers. Before the first public release, amendments are recorded in the
 * WIRE-CONTRACT.md version history (v1.1). After it, changing frozen fields, event
 * type strings, or delivery semantics is a breaking change requiring a major version bump.
 *
 * Canonical spec: https://github.com/shahzad-io/agenticgraph/blob/main/docs/streaming/WIRE-CONTRACT.md
 *
 * FROZEN PUBLIC surface:
 * - Wire body: {@see self::FROZEN_WIRE_BODY_KEYS} (snake_case JSON; `type` is NOT in the body)
 * - Event `type` carried externally (SSE `event:` line / broadcast event name)
 * - Closed framework/lifecycle type strings ({@see StreamLifecycleEventTypes}, envelope constants)
 * - Prism content type set (version-pinned to Prism ^0.102.0, i.e. >=0.102.0 <0.103.0)
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

    /** SSE event name for an author-defined type when the client asks for `?custom_events=wrap`. */
    public const SSE_CUSTOM_EVENT = 'custom_event';

    /**
     * Every enumerated (non-author) event type. An author `streamEvent()` type outside this list is a custom event.
     *
     * @var list<string>
     */
    public const ENUMERATED_TYPES = [
        'graph_start', 'graph_complete', 'graph_failed',
        'node_start', 'node_complete', 'node_error', 'node_interrupt',
        'stream_resume', self::TYPE_EXECUTION_TERMINAL, self::TYPE_STREAM_CLOSE, self::TYPE_STREAM_ERROR,
        'text_start', 'text_delta', 'text_complete',
        'tool_call', 'tool_result',
        'thinking_start', 'thinking_delta', 'thinking_complete',
        'stream_start', 'step_start', 'step_finish', 'stream_end',
        'provider_tool_event', 'artifact', 'error',
    ];

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

    public function formatSse(?string $id = null, bool $wrapCustom = false): string
    {
        $resolvedId = $id ?? (string) $this->seq;

        // A browser EventSource only delivers named events to listeners registered by name, so a client that cannot
        // list author names asks for one fixed name and gets the author's name in the data.
        if ($wrapCustom && ! in_array($this->type, self::ENUMERATED_TYPES, true)) {
            return self::formatSseRaw($resolvedId, self::SSE_CUSTOM_EVENT, $this->toWireArray() + ['event_type' => $this->type]);
        }

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

    /**
     * The error fields a browser may see for a failed run (`graph_failed`, `node_error`, the failed
     * `execution_terminal.meta`, broadcast `stream_close.meta`). Exception text can hold internals (hosts,
     * credentials in a DSN, SQL), so by default every failure reads `ExecutionFailed` / `Execution failed`.
     * With `streaming.expose_error_details` the exception class and message are sent, the message cut to
     * `tracing.capture.max_length`; `tracing.capture.errors = class` still removes the message.
     *
     * A node failed by a model provider exception (Prism or HTTP client, also wrapped) reads `rate_limited`,
     * `provider_unavailable` or `provider_error` with a fixed message instead. `stream_error` uses the same rule with its
     * own generic values (`StreamError` / `Stream error`) and no provider category.
     *
     * @return array{error_type: string, message: string}
     */
    public static function clientError(\Throwable $e, string $genericType = 'ExecutionFailed', string $genericMessage = 'Execution failed', bool $providerCategory = true): array
    {
        $generic = ['error_type' => $genericType, 'message' => $genericMessage];
        if (! (bool) config('agenticgraph.streaming.expose_error_details', false)) {
            // A model provider failure is useful to the visitor and safe to name: a fixed category, never the text.
            $category = $providerCategory ? self::exceptionCategory($e) : null;

            return $category === null ? $generic : ['error_type' => $category, 'message' => self::providerMessage($category)];
        }

        $message = strtolower((string) config('agenticgraph.tracing.capture.errors', 'message')) === 'class'
            ? $generic['message']
            : CaptureDepth::cut($e->getMessage(), CaptureDepth::maxLength());

        return ['error_type' => $e::class, 'message' => $message];
    }

    /**
     * The fields a browser may see for a model provider's in-stream `error` event. By default the package picks a fixed
     * category (`rate_limited`, `provider_unavailable`, `provider_error`) with a fixed message and drops the provider's
     * metadata, never the provider's text, which can hold keys, account ids or internal URLs. With
     * `streaming.expose_error_details` the provider's type, message (cut to `tracing.capture.max_length`, removed to the
     * generic text by `tracing.capture.errors = class`) and metadata are sent.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array{error_type: string, message: string, metadata: array<string, mixed>|null}
     */
    public static function clientProviderError(string $errorType, string $message, ?array $metadata): array
    {
        $category = self::providerErrorCategory($errorType);
        $generic = self::providerMessage($category);

        if (! (bool) config('agenticgraph.streaming.expose_error_details', false)) {
            return ['error_type' => $category, 'message' => $generic, 'metadata' => null];
        }

        return [
            'error_type' => $errorType,
            'message' => strtolower((string) config('agenticgraph.tracing.capture.errors', 'message')) === 'class'
                ? $generic
                : CaptureDepth::cut($message, CaptureDepth::maxLength()),
            'metadata' => $metadata,
        ];
    }

    /**
     * The fields of a failed `tool_result` a browser may see: `Tool failed` by default, in `error` and in `result`
     * (Prism puts the error text in both). With `streaming.expose_error_details` the tool's error, cut to
     * `tracing.capture.max_length`; `tracing.capture.errors = class` still sends `Tool failed`. The model always gets the
     * real error: only the wire payload is changed.
     */
    public static function clientToolError(?string $error): string
    {
        if (! (bool) config('agenticgraph.streaming.expose_error_details', false)
            || $error === null
            || strtolower((string) config('agenticgraph.tracing.capture.errors', 'message')) === 'class') {
            return 'Tool failed';
        }

        return CaptureDepth::cut($error, CaptureDepth::maxLength());
    }

    private static function providerMessage(string $category): string
    {
        return match ($category) {
            'rate_limited' => 'The model provider is rate limiting requests',
            'provider_unavailable' => 'The model provider is unavailable',
            default => 'The model provider returned an error',
        };
    }

    /**
     * The fixed category of a model provider exception, from its type (and its previous exceptions), or null when it
     * is not a provider failure.
     */
    private static function exceptionCategory(\Throwable $e): ?string
    {
        for ($current = $e, $depth = 0; $current !== null && $depth < 10; $current = $current->getPrevious(), $depth++) {
            $category = match (true) {
                $current instanceof PrismRateLimitedException => 'rate_limited',
                $current instanceof PrismProviderOverloadedException, $current instanceof ConnectionException => 'provider_unavailable',
                $current instanceof RequestException => match (true) {
                    $current->response->status() === 429 => 'rate_limited',
                    $current->response->status() >= 500 => 'provider_unavailable',
                    default => 'provider_error',
                },
                $current instanceof PrismException => 'provider_error',
                default => null,
            };
            if ($category !== null) {
                return $category;
            }
        }

        return null;
    }

    private static function providerErrorCategory(string $errorType): string
    {
        $type = strtolower($errorType);

        return match (true) {
            str_contains($type, 'rate') && str_contains($type, 'limit'), str_contains($type, 'too_many') => 'rate_limited',
            str_contains($type, 'overload'), str_contains($type, 'unavailable'), str_contains($type, 'timeout'),
            str_contains($type, 'timed_out'), str_contains($type, 'server_error'), str_contains($type, 'internal_error'),
            str_contains($type, 'api_error') => 'provider_unavailable',
            default => 'provider_error',
        };
    }
}
