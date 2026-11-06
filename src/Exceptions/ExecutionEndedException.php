<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Exceptions;

use RuntimeException;

/**
 * The execution ended elsewhere (for example agenticgraph:executions:expire failed it) while this worker still
 * ran it. The worker stops and writes nothing more; the jobs catch it and exit without failing.
 */
final class ExecutionEndedException extends RuntimeException
{
    public function __construct(public readonly string $executionId, ?\Throwable $previous = null)
    {
        parent::__construct("Execution {$executionId} already ended; this worker stops without writing.", 0, $previous);
    }
}
