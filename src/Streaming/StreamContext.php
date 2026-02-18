<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;

final class StreamContext
{
    private ?StreamSinkInterface $sink = null;

    public function __construct(
        private readonly StreamSession $session,
        ?StreamSinkInterface $sink = null
    ) {
        $this->sink = $sink;
    }

    public function session(): StreamSession
    {
        return $this->session;
    }

    public function sink(): ?StreamSinkInterface
    {
        return $this->sink;
    }

    public function setSink(?StreamSinkInterface $sink): void
    {
        $this->sink = $sink;
    }
}
