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
 * Broadcasts each event on the stream's channel (`private-` plus the prefix, unless `streaming.broadcast.private` is
 * false). The store is written before this sink (WrappedStreamSink), so a broadcast failure loses nothing a reader
 * of the store needs, and it does not fail the run: a frame over `streaming.broadcast.max_bytes` is skipped, and
 * after the first failed call the sink stops broadcasting for the run. `streaming.broadcast.fatal` restores the old
 * behavior: every frame is sent and an error fails the run.
 *
 * Text and thinking deltas are buffered and sent as one `batch` frame (`streaming.broadcast.batch_ms`, 0 = off).
 * Every send is a direct call, never queued: queued sends lose the order across queue workers.
 */
final class BroadcastSink implements StreamSinkInterface
{
    private const BATCHED = ['text_delta', 'thinking_delta'];

    /**
     * Room a batch leaves under max_bytes for the request line and headers: Reverb's max_request_size counts the whole
     * HTTP request, not the body. ponytail: fixed allowance, 361 bytes measured with the Pusher SDK on Reverb; a
     * single frame keeps the body-only check of 0.2.12.
     */
    private const HEADER_BYTES = 1024;

    private readonly string $channel;

    private bool $down = false;

    /** @var list<array<string, mixed>> buffered deltas: `type` plus the nine wire keys */
    private array $batch = [];

    private float $batchStartedAt = 0.0;

    public function __construct(
        private readonly Broadcaster $broadcaster,
        private readonly string $streamId,
        ?string $channelPrefix = null
    ) {
        $this->channel = $channelPrefix === null ? self::channelName($streamId) : $channelPrefix.$streamId;
    }

    /** The channel a stream's frames go to: the private name, or the public one with `streaming.broadcast.private` false. */
    public static function channelName(string $streamId): string
    {
        return (bool) config('agenticgraph.streaming.broadcast.private', true)
            ? self::privateChannelName($streamId)
            : config('agenticgraph.streaming.broadcast.channel_prefix', 'agenticgraph.stream.').$streamId;
    }

    /** The private channel name of a stream: `private-` plus the public name. The channel auth route checks against it. */
    public static function privateChannelName(string $streamId): string
    {
        return 'private-'.config('agenticgraph.streaming.broadcast.channel_prefix', 'agenticgraph.stream.').$streamId;
    }

    /**
     * ponytail: PHP has no timer in the token loop, so the window closes at the next delta: during a model pause the
     * buffered deltas wait for it. Any other event, the end of a step and close() flush them. A server timer would
     * close the window on time (spec 7.1).
     */
    public function emit(StreamEvent $event): void
    {
        $envelope = StreamEventEnvelope::fromStreamEvent($event);
        $batchMs = (int) config('agenticgraph.streaming.broadcast.batch_ms', 50);
        if ($batchMs <= 0 || ! in_array($envelope->type, self::BATCHED, true)) {
            $this->flush();
            $this->send($envelope->type, $envelope->toWireArray());

            return;
        }

        $item = ['type' => $envelope->type] + $envelope->toWireArray();
        $maxBytes = (int) config('agenticgraph.streaming.broadcast.max_bytes', 10000);
        if ($this->batch !== [] && $maxBytes > 0 && $this->bytes('batch', $this->batchBody([...$this->batch, $item])) > $maxBytes - self::HEADER_BYTES) {
            $this->flush();
        }
        if ($this->batch === []) {
            $this->batchStartedAt = microtime(true);
        }
        $this->batch[] = $item;
        if ((microtime(true) - $this->batchStartedAt) * 1000 >= $batchMs) {
            $this->flush();
        }
    }

    /** Flushes the batch, then sends `stream_close`. An interrupt (`cancelled`) does not end the stream: no close frame. */
    public function close(StreamStatus $status, mixed $meta = null): void
    {
        $this->flush();
        if ($status === StreamStatus::CANCELLED) {
            return;
        }
        $this->send(StreamEventEnvelope::TYPE_STREAM_CLOSE, StreamEventEnvelope::streamCloseWirePayload($status, $meta));
    }

    /** One buffered delta goes as a normal frame, two or more as one `batch` frame. A delta over max_bytes is skipped by send(). */
    private function flush(): void
    {
        $batch = $this->batch;
        $this->batch = [];
        if (count($batch) === 1) {
            $type = $batch[0]['type'];
            unset($batch[0]['type']);
            $this->send($type, $batch[0]);
        } elseif ($batch !== []) {
            $this->send('batch', $this->batchBody($batch));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    private function batchBody(array $events): array
    {
        return ['stream_id' => $events[0]['stream_id'], 'execution_id' => $events[0]['execution_id'], 'events' => $events];
    }

    /**
     * The request body as the Pusher API sends it: the data is JSON again inside the JSON body.
     *
     * @param  array<string, mixed>  $payload
     */
    private function bytes(string $event, array $payload): int
    {
        return strlen((string) json_encode(['name' => $event, 'data' => json_encode($payload), 'channels' => [$this->channel]]));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $event, array $payload): void
    {
        $channels = [$this->channel];
        if ((bool) config('agenticgraph.streaming.broadcast.fatal', false)) {
            $this->broadcaster->broadcast($channels, $event, $payload);

            return;
        }
        if ($this->down) {
            return;
        }
        $bytes = $this->bytes($event, $payload);
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
