<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\Sinks\SSESink;
use Symfony\Component\HttpFoundation\Response;

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

    public function response(): Response
    {
        if (! $this->sink instanceof SSESink) {
            return response('', 204);
        }

        $content = $this->sink->content();

        return response()->stream(
            static function () use ($content): void {
                echo $content;
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
            ]
        );
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

        if (method_exists($this->sink, 'lines')) {
            $lines = $this->sink->lines();
            if ($lines instanceof \Iterator) {
                return $lines;
            }
            if ($lines instanceof \Traversable) {
                return new \IteratorIterator($lines);
            }
        }

        return new \ArrayIterator([]);
    }
}
