<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Exceptions;

use RuntimeException;

/**
 * The app's skipQueuedRunsUsing() callback skipped a queued run. It is never thrown: it describes the failure on the
 * stream, so its message must not carry the app's reason, which stays in the execution's error and the log.
 */
final class QueuedRunSkippedException extends RuntimeException
{
    public const REASON = 'skipped';

    public function __construct(public readonly string $executionId)
    {
        parent::__construct("Execution {$executionId} was skipped before it ran.");
    }
}
