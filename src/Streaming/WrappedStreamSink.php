<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Store\RetentionPolicy;

final class WrappedStreamSink implements StreamSinkInterface
{
    public function __construct(
        private readonly StreamSinkInterface $sink,
        private readonly ?StreamStoreInterface $store,
        private readonly mixed $nodeFilter,
        private readonly mixed $eventTypeFilter,
        private readonly ?RetentionPolicy $retention,
        private readonly StreamObserver $observer,
        private readonly StreamErrorHandler $errorHandler
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

                return;
            }
        }
        try {
            $this->sink->emit($event);
            $this->observer->record($event);
        } catch (\Throwable $e) {
            $this->handleError($e, $event, 'emit');
        }
    }

    public function close(StreamStatus $status, mixed $meta = null): void
    {
        $this->sink->close($status, $meta);
        $this->observer->flush();
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
            $this->sink->emit(new StreamEventImpl(
                $event->streamId(),
                $event->executionId(),
                $event->nodeName(),
                $event->spanId(),
                $event->parentSpanId(),
                'stream_error',
                $decision->payload,
                $event->seq(),
                $event->nodeSeq(),
                new \DateTimeImmutable('now')
            ));
        } catch (\Throwable) {
        }
        if ($decision->fatal) {
            try {
                $this->sink->close(StreamStatus::ERROR, $decision->payload);
            } catch (\Throwable) {
            }
            throw $error;
        }
    }
}
