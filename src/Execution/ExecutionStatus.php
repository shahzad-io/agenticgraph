<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

enum ExecutionStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case INTERRUPTED = 'interrupted';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
