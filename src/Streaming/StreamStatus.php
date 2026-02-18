<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

enum StreamStatus: string
{
    case SUCCESS = 'success';
    case CANCELLED = 'cancelled';
    case ERROR = 'error';
}
