<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

enum StreamingMode: string
{
    case AUTO = 'auto';
    case NONE = 'none';
    case SSE = 'sse';
    case BROADCAST = 'broadcast';
    case ITERATOR = 'iterator';
}
