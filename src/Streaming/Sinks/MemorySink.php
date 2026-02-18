<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Sinks;

use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\StreamStatus;

final class MemorySink implements StreamSinkInterface
{
    private mixed $events;

    private ?StreamStatus $status = null;

    private mixed $meta = null;

    public function __construct()
    {
        $this->events = new \SplDoublyLinkedList;
    }

    public function emit(StreamEvent $event): void
    {
        $this->events->push($event);
    }

    public function close(StreamStatus $status, mixed $meta = null): void
    {
        $this->status = $status;
        $this->meta = $meta;
    }

    public function events(): mixed
    {
        return $this->events;
    }

    public function status(): ?StreamStatus
    {
        return $this->status;
    }

    public function meta(): mixed
    {
        return $this->meta;
    }
}
