<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\Sinks\NullTransportSink;
use Shahzad\AgenticGraph\Streaming\Sinks\RateLimitedSink;

final class StreamSinkFactory
{
    public function __construct(
        private readonly Broadcaster $broadcaster,
        private readonly StreamStoreInterface $store,
        private readonly bool $rateLimitEnabled,
        private readonly int $rateLimitMaxEventsPerSecond,
        private readonly bool $retentionEnabled,
        private readonly RetentionPolicyFactory $retentionPolicyFactory,
        private readonly StreamObserverFactory $observerFactory,
        private readonly StreamErrorHandler $errorHandler
    ) {}

    public function create(StreamSession $session, StreamOptions $options): StreamSinkInterface
    {
        return match ($options->delivery) {
            'broadcast' => new BroadcastSink($this->broadcaster, $session->streamId),
            'iterator' => new MemorySink,
            'sse' => new NullTransportSink,
            default => new MemorySink,
        };
    }

    public function wrap(StreamSinkInterface $sink, StreamOptions $options, StreamSession $session): StreamSinkInterface
    {
        $store = $options->storeEnabled ? $this->store : null;
        $nodeFilter = $options->nodeFilter;
        $eventTypeFilter = $options->eventTypeFilter;
        if ($this->rateLimitEnabled) {
            $sink = new RateLimitedSink($sink, $this->rateLimitMaxEventsPerSecond);
        }
        $retention = $store && $this->retentionEnabled
            ? $this->retentionPolicyFactory->create()
            : null;
        $observer = $this->observerFactory->create($session);

        return new WrappedStreamSink(
            $sink,
            $store,
            $nodeFilter,
            $eventTypeFilter,
            $retention,
            $observer,
            $this->errorHandler,
            $session
        );
    }
}
