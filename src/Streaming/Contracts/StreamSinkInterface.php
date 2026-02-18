<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Contracts;

use Shahzad\AgenticGraph\Streaming\StreamStatus;

interface StreamSinkInterface
{
    public function emit(StreamEvent $event): void;

    public function close(StreamStatus $status, mixed $meta = null): void;
}
