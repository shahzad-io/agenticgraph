<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Execution\StreamingMode;

/**
 * Describes how to consume a streaming execution (not a frozen public contract). `toArray()` is what an app passes
 * to the browser; the stream client builds its transport from it.
 */
final class StreamDescriptor
{
    public const ROUTE_NAME = 'agenticgraph.streams.show';

    public const REPLAY_ROUTE_NAME = 'agenticgraph.streams.events';

    public const AUTH_ROUTE_NAME = 'agenticgraph.streams.auth';

    public function __construct(
        public readonly ?string $streamId = null,
        public readonly ?string $streamUrl = null,
        public readonly ?\Iterator $inline = null,
        public readonly ?StreamingMode $mode = null,
        public readonly ?string $token = null,
        public readonly ?string $replayUrl = null,
        public readonly ?string $authUrl = null,
        public readonly ?string $channel = null,
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

    /** `broadcast`, `sse` (a stream URL to read), or null (nothing for a browser). */
    public function transport(): ?string
    {
        return match (true) {
            $this->mode === StreamingMode::BROADCAST => 'broadcast',
            $this->hasUrl() => 'sse',
            default => null,
        };
    }

    /**
     * @return array{transport: ?string, stream_id: ?string, stream_url: ?string, replay_url: ?string, auth_url: ?string, token: ?string, channel: ?string}
     */
    public function toArray(): array
    {
        return [
            'transport' => $this->transport(),
            'stream_id' => $this->streamId,
            'stream_url' => $this->streamUrl,
            'replay_url' => $this->replayUrl,
            'auth_url' => $this->authUrl,
            'token' => $this->token,
            'channel' => $this->channel,
        ];
    }
}
