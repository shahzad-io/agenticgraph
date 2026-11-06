<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Exceptions;

use RuntimeException;

/**
 * No worker ran the execution: it stayed queued, or its worker died while it was running, and
 * agenticgraph:executions:expire ended it.
 */
final class StaleExecutionException extends RuntimeException
{
    public const REASON_QUEUED = 'stale_queued';

    public const REASON_RUNNING = 'stale_running';

    public function __construct(public readonly string $executionId, public readonly string $reason, int $seconds)
    {
        parent::__construct(sprintf(
            'Execution %s was %s for more than %d seconds with no worker (%s).',
            $executionId,
            $reason === self::REASON_QUEUED ? 'queued' : 'running',
            $seconds,
            $reason,
        ));
    }
}
