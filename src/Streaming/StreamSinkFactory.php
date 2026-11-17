<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\Sinks\NullTransportSink;
use Shahzad\AgenticGraph\Streaming\Sinks\RateLimitedSink;

final class StreamSinkFactory
{
    private ?Broadcaster $streamBroadcaster = null;

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
            'broadcast' => new BroadcastSink($this->streamBroadcaster(), $session->streamId),
            'iterator' => new MemorySink,
            'sse' => new NullTransportSink,
            default => new MemorySink,
        };
    }

    /**
     * The app's Pusher or Reverb broadcaster connects with a 10 s timeout, so with Reverb down the first frame stalled
     * the run 10 s. Build the same connection with `streaming.broadcast.timeout` for stream frames; a timeout the app
     * set in `client_options` or `options` wins. Any other broadcaster is used as bound.
     */
    private function streamBroadcaster(): Broadcaster
    {
        if ($this->streamBroadcaster !== null) {
            return $this->streamBroadcaster;
        }
        $config = config('broadcasting.connections.'.config('broadcasting.default'));
        $timeout = (float) config('agenticgraph.streaming.broadcast.timeout', 2);
        if (! (bool) config('agenticgraph.streaming.broadcast.fatal', false) && $timeout > 0
            && $this->broadcaster instanceof PusherBroadcaster
            && is_array($config) && in_array($config['driver'] ?? null, ['pusher', 'reverb'], true)) {
            $config['client_options'] = ($config['client_options'] ?? []) + ['connect_timeout' => $timeout, 'timeout' => $timeout];
            $config['options'] = ($config['options'] ?? []) + ['timeout' => $timeout];

            return $this->streamBroadcaster = new PusherBroadcaster(app(BroadcastManager::class)->pusher($config), $config['jsonp'] ?? false);
        }

        return $this->streamBroadcaster = $this->broadcaster;
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
