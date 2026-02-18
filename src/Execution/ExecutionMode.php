<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

enum ExecutionMode: string
{
    case SYNC = 'sync';
    case ASYNC = 'async';
}
