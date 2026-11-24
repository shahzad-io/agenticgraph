<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Exceptions\ExecutionEndedException;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;
use Shahzad\AgenticGraph\Streaming\Store\RetentionPolicy;

final class WrappedStreamSink implements StreamSinkInterface
{
    private bool $terminalAppended = false;

    /** Set by the first close(); later closes and emits are dropped, so execution_terminal and stream_close stay last. */
    private bool $closed = false;

    /** Seconds between heartbeats on the execution row while events flow. */
    private const HEARTBEAT_SECONDS = 10;

    private ?int $lastHeartbeat = null;

    public function __construct(
        private readonly StreamSinkInterface $sink,
        private readonly ?StreamStoreInterface $store,
        private readonly mixed $nodeFilter,
        private readonly mixed $eventTypeFilter,
        private readonly ?RetentionPolicy $retention,
        private readonly StreamObserver $observer,
        private readonly StreamErrorHandler $errorHandler,
        private readonly ?StreamSession $session = null,
        // Broadcast only: send the stored execution_terminal to the sink before its close (stream_close).
        private readonly bool $emitTerminal = false,
        // Broadcast only: told of each event the filter drops, so it can flush its batch (the event is never sent).
        private readonly ?BroadcastSink $broadcast = null,
    ) {}

    public function emit(StreamEvent $event): void
    {
        if ($this->closed) {
            return;
        }
        if (! $this->shouldEmit($event)) {
            $this->broadcast?->dropped($event);

            return;
        }
        $this->heartbeat($event);
        if ($this->store) {
            try {
                $this->store->append($event);
                if ($this->retention) {
                    $this->retention->enforce($this->store, $event);
                }
            } catch (\Throwable $e) {
                $this->handleError($e, $event, 'store');
            }
        }
        try {
            $this->sink->emit($event);
        } catch (\Throwable $e) {
            $this->handleError($e, $event, 'transport');
        }
        try {
            $this->observer->record($event);
        } catch (\Throwable $e) {
            $this->handleError($e, $event, 'observer');
        }

        $this->recordContentEmissionForRetryGate($event);
    }

    /**
     * A streaming node is alive: refresh the execution row at most once per HEARTBEAT_SECONDS, so
     * agenticgraph:executions:expire leaves it alone. The first event after a quiet gap always checks, so once the
     * execution has ended elsewhere, nothing more is written and the worker stops.
     *
     * graph_complete and graph_failed are not checked. The coordinator emits them only after its own conditional
     * status write (completed() or failed()) made this worker the owner of the end, so the row is already ended and
     * touch() would return false. Their terminal must always be written.
     *
     * ponytail: fixed 10 s beat; a running limit (timeout + stale_grace) under 10 s can still expire a streaming run.
     */
    private function heartbeat(StreamEvent $event): void
    {
        if (in_array($event->type(), [StreamLifecycleEventTypes::GRAPH_COMPLETE, StreamLifecycleEventTypes::GRAPH_FAILED], true)) {
            return;
        }
        $now = now()->getTimestamp();
        if ($this->lastHeartbeat !== null && $now - $this->lastHeartbeat < self::HEARTBEAT_SECONDS) {
            return;
        }
        $this->lastHeartbeat = $now;

        if (! app(ExecutionRepository::class)->touch($event->executionId())) {
            $this->closed = true;

            throw new ExecutionEndedException($event->executionId());
        }
    }

    private function recordContentEmissionForRetryGate(StreamEvent $event): void
    {
        if (StreamLifecycleEventTypes::isLifecycleOrFrameworkType($event->type())) {
            return;
        }

        try {
            if (! app()->bound(StreamContext::class)) {
                return;
            }
            app(StreamContext::class)->markNodeAttemptContentEmission();
        } catch (\Throwable) {
        }
    }

    public function close(StreamStatus $status, mixed $meta = null): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $terminal = $this->appendStoreTerminalIfNeeded($status, $meta);
        if ($terminal !== null && $this->emitTerminal) {
            $this->sink->emit($terminal);
        }
        $this->sink->close($status, $meta);
        $this->observer->flush();
    }

    /** The stored execution_terminal, or null when none was stored now. */
    private function appendStoreTerminalIfNeeded(StreamStatus $status, mixed $meta): ?StreamEvent
    {
        if ($this->store === null || $this->session === null || $this->terminalAppended) {
            return null;
        }

        if (! is_array($meta) || ! array_key_exists('final_state', $meta)) {
            return null;
        }

        $finalState = $meta['final_state'];
        $terminalMeta = $meta;
        unset($terminalMeta['final_state']);
        if ($terminalMeta === []) {
            $terminalMeta = null;
        }

        $envelope = StreamEventEnvelope::executionTerminal(
            $this->session,
            StreamTerminalStatus::fromStreamStatus($status),
            $finalState,
            $terminalMeta
        );

        try {
            $event = $envelope->toStreamEvent();
            $this->store->append($event);
            if ($this->retention) {
                $this->retention->enforce($this->store, $event);
            }
            $this->terminalAppended = true;

            return $event;
        } catch (\Throwable $e) {
            $this->handleError($e, $envelope->toStreamEvent(), 'store');

            return null;
        }
    }

    private function shouldEmit(StreamEvent $event): bool
    {
        if ($this->eventTypeFilter !== null) {
            if (is_callable($this->eventTypeFilter)) {
                if (! (bool) ($this->eventTypeFilter)($event)) {
                    return false;
                }
            } elseif (is_array($this->eventTypeFilter)) {
                if (! in_array($event->type(), $this->eventTypeFilter, true)) {
                    return false;
                }
            }
        }

        if ($this->nodeFilter !== null && $event->nodeName() !== null) {
            if (is_callable($this->nodeFilter)) {
                if (! (bool) ($this->nodeFilter)($event)) {
                    return false;
                }
            } elseif (is_array($this->nodeFilter)) {
                if (! in_array($event->nodeName(), $this->nodeFilter, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function handleError(\Throwable $error, StreamEvent $event, string $stage): void
    {
        $decision = $this->errorHandler->decide($error, $event, $stage);
        $this->observer->recordError();
        try {
            $envelope = new StreamEventEnvelope(
                type: StreamEventEnvelope::TYPE_STREAM_ERROR,
                streamId: $event->streamId(),
                executionId: $event->executionId(),
                nodeName: $event->nodeName(),
                spanId: $event->spanId(),
                parentSpanId: $event->parentSpanId(),
                seq: $event->seq(),
                nodeSeq: $event->nodeSeq(),
                occurredAt: new \DateTimeImmutable('now'),
                payload: $decision->payload,
            );
            $this->sink->emit($envelope->toStreamEvent());
        } catch (\Throwable) {
        }
        if ($decision->fatal) {
            // No close here: the coordinator's failure path emits graph_failed, then closes once.
            throw $error;
        }

        $context = [
            'stage' => $stage,
            'error_type' => $error::class,
            'message' => $error->getMessage(),
            'stream_id' => $event->streamId(),
            'execution_id' => $event->executionId(),
            'event_type' => $event->type(),
            'event_seq' => $event->seq(),
            'store_entry_id' => $event->seq().'-0',
        ];

        Log::warning(
            $stage === 'store' ? 'Non-fatal stream store write failed' : 'Non-fatal stream error',
            $context
        );
    }
}
