<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

/**
 * Dispatched once when skipQueuedRunsUsing() skips a queued run, after the row has ended as failed with ended_by=skip.
 * GraphExecutionFailed does not fire for a skip. The event is not broadcast, and the reason is the app's own text: it
 * is never sent to the browser.
 */
class GraphExecutionSkipped
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $graphName,
        /** The string your skipQueuedRunsUsing() callback returned. */
        public readonly string $reason,
    ) {}
}
