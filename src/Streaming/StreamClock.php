<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

interface StreamClock
{
    public function now(): float;
}
