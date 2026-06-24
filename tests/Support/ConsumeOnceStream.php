<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 stream that drains on the first full read (via __toString or read-to-EOF),
 * simulating a live SSE body that Laravel's Response::body() would consume.
 */
final class ConsumeOnceStream implements StreamInterface
{
    private string $remaining;

    public function __construct(string $content)
    {
        $this->remaining = $content;
    }

    public function __toString(): string
    {
        $all = $this->remaining;
        $this->remaining = '';

        return $all;
    }

    public function close(): void
    {
        $this->remaining = '';
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return $this->remaining === '' ? 0 : strlen($this->remaining);
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return $this->remaining === '';
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void {}

    public function rewind(): void {}

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        return 0;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        if ($this->remaining === '') {
            return '';
        }

        $data = substr($this->remaining, 0, $length);
        $this->remaining = substr($this->remaining, strlen($data));

        return $data;
    }

    public function getContents(): string
    {
        return $this->__toString();
    }

    public function getMetadata($key = null)
    {
        return $key === null ? [] : null;
    }
}
