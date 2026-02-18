<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Events\StreamStatsUpdated;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;

final class StreamObserver
{
    private int $sinceLastEmit = 0;

    private float $lastEmitAt;

    public function __construct(
        private readonly StreamMetrics $metrics,
        private readonly string $streamId,
        private readonly string $executionId,
        private readonly float $startedAt,
        private readonly int $emitEveryEvents = 100,
        private readonly int $emitEverySeconds = 10
    ) {
        $this->lastEmitAt = microtime(true);
    }

    public function record(StreamEvent $event): void
    {
        $this->metrics->recordEvent($event);
        $this->sinceLastEmit++;
        $this->maybeEmit();
    }

    public function recordError(): void
    {
        $this->metrics->recordError();
        $this->maybeEmit(true);
    }

    public function recordReplay(): void
    {
        $this->metrics->recordReplay();
        $this->maybeEmit();
    }

    public function recordReconnect(): void
    {
        $this->metrics->recordReconnect();
        $this->maybeEmit();
    }

    public function flush(): void
    {
        $this->emitStats();
    }

    private function maybeEmit(bool $force = false): void
    {
        $now = microtime(true);
        if ($force || $this->sinceLastEmit >= $this->emitEveryEvents || ($now - $this->lastEmitAt) >= $this->emitEverySeconds) {
            $this->emitStats();
        }
    }

    private function emitStats(): void
    {
        $this->sinceLastEmit = 0;
        $this->lastEmitAt = microtime(true);
        app('events')->dispatch(new StreamStatsUpdated(
            $this->streamId,
            $this->executionId,
            $this->metrics->toPayload($this->startedAt)
        ));
    }
}
