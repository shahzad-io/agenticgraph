<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * Event dispatcher that supports batching of high-frequency events.
 *
 * This dispatcher buffers events that implement BatchableEventInterface
 * and periodically flushes them to reduce overhead in high-volume scenarios.
 *
 * @example
 * ```php
 * $dispatcher = new BatchedEventDispatcher($events, batchSize: 100, flushIntervalSeconds: 5);
 *
 * // Events are buffered and dispatched in batches
 * $dispatcher->dispatch(new TokensUsed(...));
 * $dispatcher->dispatch(new TokensUsed(...));
 *
 * // Force flush all pending events
 * $dispatcher->flush();
 *
 * // Register periodic flush on shutdown
 * register_shutdown_function([$dispatcher, 'flush']);
 * ```
 */
class BatchedEventDispatcher
{
    /**
     * @var array<string, BatchableEventInterface>
     */
    private array $buffer = [];

    private int $batchSize;

    private float $flushInterval;

    private float $lastFlushTime;

    private Dispatcher $events;

    private ?LoggerInterface $logger;

    private bool $enabled;

    public function __construct(
        Dispatcher $events,
        ?LoggerInterface $logger = null,
        ?int $batchSize = null,
        ?float $flushIntervalSeconds = null,
        ?bool $enabled = null
    ) {
        $this->events = $events;
        $this->logger = $logger;
        $this->batchSize = $batchSize ?? (int) config('agenticgraph.events.batching.batch_size', 100);
        $this->flushInterval = $flushIntervalSeconds ?? (float) config('agenticgraph.events.batching.flush_interval_seconds', 5);
        $this->enabled = $enabled ?? (bool) config('agenticgraph.events.batching.enabled', false);
        $this->lastFlushTime = microtime(true);
    }

    /**
     * Dispatch an event, buffering if batchable.
     *
     * @param  object  $event  The event to dispatch
     */
    public function dispatch(object $event): void
    {
        if (! $this->enabled || ! $event instanceof BatchableEventInterface) {
            // Non-batchable events or disabled batching - dispatch immediately
            $this->events->dispatch($event);

            return;
        }

        $this->bufferEvent($event);
    }

    /**
     * Buffer a batchable event.
     */
    private function bufferEvent(BatchableEventInterface $event): void
    {
        $key = $event->getBatchKey();

        if (isset($this->buffer[$key])) {
            // Merge with existing event of same key
            $this->buffer[$key] = $this->buffer[$key]->merge($event);
        } else {
            $this->buffer[$key] = $event;
        }

        // Check if we should flush
        if ($this->shouldFlush()) {
            $this->flush();
        }
    }

    /**
     * Determine if the buffer should be flushed.
     */
    private function shouldFlush(): bool
    {
        // Flush if buffer size exceeds or equals batch size
        if (count($this->buffer) >= $this->batchSize) {
            return true;
        }

        // Flush if time interval has passed
        if ((microtime(true) - $this->lastFlushTime) >= $this->flushInterval) {
            return true;
        }

        return false;
    }

    /**
     * Flush all buffered events to the event dispatcher.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $eventsToDispatch = $this->buffer;
        $this->buffer = [];
        $this->lastFlushTime = microtime(true);

        $count = count($eventsToDispatch);

        foreach ($eventsToDispatch as $event) {
            $this->events->dispatch($event);
        }

        if ($this->logger !== null && (bool) config('agenticgraph.logging.enabled', true)) {
            $this->logger->debug('BatchedEventDispatcher flushed events', [
                'count' => $count,
                'buffer_size' => $this->batchSize,
                'flush_interval' => $this->flushInterval,
            ]);
        }
    }

    /**
     * Get the current number of buffered events.
     */
    public function getBufferedCount(): int
    {
        return count($this->buffer);
    }

    /**
     * Get the current buffer contents without flushing.
     *
     * @return array<string, BatchableEventInterface>
     */
    public function getBuffer(): array
    {
        return $this->buffer;
    }

    /**
     * Clear the buffer without dispatching events.
     */
    public function clearBuffer(): void
    {
        $this->buffer = [];
    }

    /**
     * Check if batching is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable or disable batching.
     */
    public function setEnabled(bool $enabled): void
    {
        if (! $enabled && $this->enabled) {
            // Flush any pending events when disabling
            $this->flush();
        }
        $this->enabled = $enabled;
    }

    /**
     * Get the configured batch size.
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Set the batch size.
     */
    public function setBatchSize(int $batchSize): void
    {
        $this->batchSize = $batchSize;

        // Check if we need to flush due to new size
        if ($this->shouldFlush()) {
            $this->flush();
        }
    }

    /**
     * Get the configured flush interval in seconds.
     */
    public function getFlushInterval(): float
    {
        return $this->flushInterval;
    }

    /**
     * Set the flush interval in seconds.
     */
    public function setFlushInterval(float $flushInterval): void
    {
        $this->flushInterval = $flushInterval;

        // Check if we need to flush due to time change
        if ($this->shouldFlush()) {
            $this->flush();
        }
    }
}
