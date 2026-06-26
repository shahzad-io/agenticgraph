<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;

final class StreamContext
{
    private ?StreamSinkInterface $sink = null;

    private bool $nodeAttemptHasContentEmission = false;

    /** @var array<string, int> */
    private array $nodeSeqByName = [];

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

    public function isStreamingActive(): bool
    {
        return $this->sink !== null;
    }

    public function resetNodeAttemptEmission(): void
    {
        $this->nodeAttemptHasContentEmission = false;
    }

    public function markNodeAttemptContentEmission(): void
    {
        $this->nodeAttemptHasContentEmission = true;
    }

    public function nodeAttemptHasContentEmission(): bool
    {
        return $this->nodeAttemptHasContentEmission;
    }

    public function resetNodeSeq(string $nodeName): void
    {
        unset($this->nodeSeqByName[$nodeName]);
    }

    public function nextNodeSeq(string $nodeName): int
    {
        $current = $this->nodeSeqByName[$nodeName] ?? 0;
        $next = $current + 1;
        $this->nodeSeqByName[$nodeName] = $next;

        return $next;
    }
}
