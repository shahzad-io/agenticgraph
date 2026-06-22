<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Execution\StreamingMode;

/**
 * Describes how to consume a streaming execution (not a frozen public contract).
 */
final class StreamDescriptor
{
    public const ROUTE_NAME = 'agenticgraph.streams.show';

    public function __construct(
        public readonly ?string $streamId = null,
        public readonly ?string $streamUrl = null,
        public readonly ?\Iterator $inline = null,
        public readonly ?StreamingMode $mode = null,
    ) {}

    public static function none(): self
    {
        return new self(mode: StreamingMode::NONE);
    }

    public function hasUrl(): bool
    {
        return $this->streamUrl !== null && $this->streamUrl !== '';
    }

    public function isInline(): bool
    {
        return $this->inline !== null;
    }

    public function hasStream(): bool
    {
        return $this->isInline() || $this->hasUrl();
    }
}
