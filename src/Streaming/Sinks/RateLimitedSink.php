<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Sinks;

use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\StreamClock;
use Shahzad\AgenticGraph\Streaming\StreamStatus;
use Shahzad\AgenticGraph\Streaming\SystemStreamClock;

final class RateLimitedSink implements StreamSinkInterface
{
    private float $windowStart;

    private int $emittedInWindow = 0;

    private ?StreamEventNode $head = null;

    private ?StreamEventNode $tail = null;

    private StreamClock $clock;

    public function __construct(
        private readonly StreamSinkInterface $sink,
        private readonly int $maxEventsPerSecond,
        ?StreamClock $clock = null
    ) {
        $this->clock = $clock ?? new SystemStreamClock;
        $this->windowStart = $this->now();
    }

    public function emit(StreamEvent $event): void
    {
        $this->advanceWindow();
        if ($this->maxEventsPerSecond > 0 && $this->emittedInWindow >= $this->maxEventsPerSecond) {
            $this->enqueue($event);

            return;
        }

        $this->sink->emit($event);
        $this->emittedInWindow++;
    }

    public function close(StreamStatus $status, mixed $meta = null): void
    {
        $this->flushAll();
        $this->sink->close($status, $meta);
    }

    private function advanceWindow(): void
    {
        $now = $this->now();
        if ($now - $this->windowStart >= 1) {
            $this->windowStart = $now;
            $this->emittedInWindow = 0;
            $this->flushWithinLimit();
        }
    }

    private function flushWithinLimit(): void
    {
        while (! $this->isEmpty()) {
            if ($this->maxEventsPerSecond > 0 && $this->emittedInWindow >= $this->maxEventsPerSecond) {
                return;
            }
            $event = $this->dequeue();
            if (! $event) {
                return;
            }
            $this->sink->emit($event);
            $this->emittedInWindow++;
        }
    }

    private function flushAll(): void
    {
        while (! $this->isEmpty()) {
            $event = $this->dequeue();
            if (! $event) {
                return;
            }
            $this->sink->emit($event);
        }
    }

    private function now(): float
    {
        return $this->clock->now();
    }

    private function enqueue(StreamEvent $event): void
    {
        $node = new StreamEventNode($event);
        if ($this->tail === null) {
            $this->head = $node;
            $this->tail = $node;

            return;
        }
        $this->tail->next = $node;
        $this->tail = $node;
    }

    private function dequeue(): ?StreamEvent
    {
        if ($this->head === null) {
            return null;
        }
        $event = $this->head->event;
        $this->head = $this->head->next;
        if ($this->head === null) {
            $this->tail = null;
        }

        return $event;
    }

    private function isEmpty(): bool
    {
        return $this->head === null;
    }
}

final class StreamEventNode
{
    public ?self $next = null;

    public function __construct(
        public readonly StreamEvent $event
    ) {}
}
