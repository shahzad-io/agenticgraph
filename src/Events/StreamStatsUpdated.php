<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

final class StreamStatsUpdated
{
    public function __construct(
        public readonly string $streamId,
        public readonly string $executionId,
        public readonly \Shahzad\AgenticGraph\Streaming\StreamMetricsPayload $metrics
    ) {}
}
