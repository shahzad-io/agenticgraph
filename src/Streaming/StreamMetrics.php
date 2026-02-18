<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;

final class StreamMetrics
{
    private int $eventsEmitted = 0;

    private int $bytesEmitted = 0;

    private int $errors = 0;

    private int $reconnects = 0;

    private int $replays = 0;

    private int $lastSeq = 0;

    private ?float $firstEventAt = null;

    public function recordEvent(StreamEvent $event): void
    {
        $this->eventsEmitted++;
        $this->lastSeq = $event->seq();
        $this->bytesEmitted += strlen(json_encode($event->payload(), JSON_UNESCAPED_SLASHES));
        if ($this->firstEventAt === null) {
            $this->firstEventAt = microtime(true);
        }
    }

    public function recordError(): void
    {
        $this->errors++;
    }

    public function recordReplay(): void
    {
        $this->replays++;
    }

    public function recordReconnect(): void
    {
        $this->reconnects++;
    }

    public function toPayload(float $startedAt): StreamMetricsPayload
    {
        $now = microtime(true);

        return new StreamMetricsPayload(
            eventsEmitted: $this->eventsEmitted,
            bytesEmitted: $this->bytesEmitted,
            errors: $this->errors,
            reconnects: $this->reconnects,
            replays: $this->replays,
            streamDurationMs: (int) (($now - $startedAt) * 1000),
            timeToFirstByteMs: $this->firstEventAt === null ? null : (int) (($this->firstEventAt - $startedAt) * 1000),
            lastSeq: $this->lastSeq
        );
    }
}
