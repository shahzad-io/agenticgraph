<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Store\RetentionPolicy;

final class WrappedStreamSink implements StreamSinkInterface
{
    private bool $terminalAppended = false;

    public function __construct(
        private readonly StreamSinkInterface $sink,
        private readonly ?StreamStoreInterface $store,
        private readonly mixed $nodeFilter,
        private readonly mixed $eventTypeFilter,
        private readonly ?RetentionPolicy $retention,
        private readonly StreamObserver $observer,
        private readonly StreamErrorHandler $errorHandler,
        private readonly ?StreamSession $session = null,
    ) {}

    public function emit(StreamEvent $event): void
    {
        if (! $this->shouldEmit($event)) {
            return;
        }
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
        $this->appendStoreTerminalIfNeeded($status, $meta);
        $this->sink->close($status, $meta);
        $this->observer->flush();
    }

    private function appendStoreTerminalIfNeeded(StreamStatus $status, mixed $meta): void
    {
        if ($this->store === null || $this->session === null || $this->terminalAppended) {
            return;
        }

        if (! is_array($meta) || ! array_key_exists('final_state', $meta)) {
            return;
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
        } catch (\Throwable $e) {
            $this->handleError($e, $envelope->toStreamEvent(), 'store');
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
            try {
                $this->sink->close(StreamStatus::ERROR, $decision->payload);
            } catch (\Throwable) {
            }
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
