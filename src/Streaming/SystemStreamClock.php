<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

final class SystemStreamClock implements StreamClock
{
    public function now(): float
    {
        return microtime(true);
    }
}
