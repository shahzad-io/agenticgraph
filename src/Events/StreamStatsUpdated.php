<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Shahzad\AgenticGraph\Streaming\StreamMetricsPayload;

final class StreamStatsUpdated
{
    public function __construct(
        public readonly string $streamId,
        public readonly string $executionId,
        public readonly StreamMetricsPayload $metrics
    ) {}
}
