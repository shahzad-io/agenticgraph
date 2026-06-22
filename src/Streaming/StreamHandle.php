<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;

/**
 * @implements \Iterator<int, mixed>
 */
final class StreamHandle implements \Iterator
{
    private \Iterator $iterator;

    public function __construct(
        private readonly StreamSession $session,
        private readonly StreamSinkInterface $sink,
        private readonly StateContract $finalState,
    ) {
        $this->iterator = $this->resolveIterator();
    }

    public function current(): mixed
    {
        return $this->iterator->current();
    }

    public function key(): mixed
    {
        return $this->iterator->key();
    }

    public function next(): void
    {
        $this->iterator->next();
    }

    public function rewind(): void
    {
        $this->iterator->rewind();
    }

    public function valid(): bool
    {
        return $this->iterator->valid();
    }

    public function getIterator(): \Iterator
    {
        return $this->iterator;
    }

    public function getFinalState(): StateContract
    {
        return $this->finalState;
    }

    public function streamId(): string
    {
        return $this->session->streamId;
    }

    public function executionId(): string
    {
        return $this->session->executionId;
    }

    private function resolveIterator(): \Iterator
    {
        if (method_exists($this->sink, 'events')) {
            $events = $this->sink->events();
            if ($events instanceof \Iterator) {
                return $events;
            }
            if ($events instanceof \Traversable) {
                return new \IteratorIterator($events);
            }
        }

        return new \ArrayIterator([]);
    }
}
