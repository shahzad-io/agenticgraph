<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Sinks;

use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\StreamStatus;

/**
 * No-op transport sink for store-only streaming (e.g. async SSE read via StreamController).
 */
final class NullTransportSink implements StreamSinkInterface
{
    public function emit(StreamEvent $event): void
    {
    }

    public function close(StreamStatus $status, mixed $meta = null): void
    {
    }
}
