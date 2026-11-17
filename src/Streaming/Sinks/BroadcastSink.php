<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Sinks;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamStatus;

/**
 * Broadcasts each event on the stream's channel. The store is written before this sink (WrappedStreamSink), so a
 * broadcast failure loses nothing a reader of the store needs, and it does not fail the run: a frame over
 * `streaming.broadcast.max_bytes` is skipped, and after the first failed call the sink stops broadcasting for the
 * run. `streaming.broadcast.fatal` restores the old behavior: every frame is sent and an error fails the run.
 */
final class BroadcastSink implements StreamSinkInterface
{
    private readonly string $channelPrefix;

    private bool $down = false;

    public function __construct(
        private readonly Broadcaster $broadcaster,
        private readonly string $streamId,
        ?string $channelPrefix = null
    ) {
        $this->channelPrefix = $channelPrefix ?? (string) config(
            'agenticgraph.streaming.broadcast.channel_prefix',
            'agenticgraph.stream.'
        );
    }

    public function emit(StreamEvent $event): void
    {
        $envelope = StreamEventEnvelope::fromStreamEvent($event);
        $this->send($envelope->type, $envelope->toWireArray());
    }

    public function close(StreamStatus $status, mixed $meta = null): void
    {
        $this->send(StreamEventEnvelope::TYPE_STREAM_CLOSE, StreamEventEnvelope::streamCloseWirePayload($status, $meta));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $event, array $payload): void
    {
        $channels = [$this->channelPrefix.$this->streamId];
        if ((bool) config('agenticgraph.streaming.broadcast.fatal', false)) {
            $this->broadcaster->broadcast($channels, $event, $payload);

            return;
        }
        if ($this->down) {
            return;
        }
        // The request body as the Pusher API sends it: the data is JSON again inside the JSON body.
        $bytes = strlen((string) json_encode(['name' => $event, 'data' => json_encode($payload), 'channels' => $channels]));
        $maxBytes = (int) config('agenticgraph.streaming.broadcast.max_bytes', 10000);
        if ($maxBytes > 0 && $bytes > $maxBytes) {
            Log::warning('agenticgraph.broadcast.frame_skipped', ['stream_id' => $this->streamId, 'event' => $event, 'bytes' => $bytes, 'max_bytes' => $maxBytes]);

            return;
        }
        try {
            $this->broadcaster->broadcast($channels, $event, $payload);
        } catch (\Throwable $e) {
            // ponytail: any failure stops broadcasting for the run, a transient error included; the store has it all.
            $this->down = true;
            Log::warning('agenticgraph.broadcast.stopped', ['stream_id' => $this->streamId, 'event' => $event, 'error' => $e::class, 'message' => preg_replace('/\?[^\s)]*/', '', $e->getMessage())]); // the URL query holds the app key
        }
    }
}
