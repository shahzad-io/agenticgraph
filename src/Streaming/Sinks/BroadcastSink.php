<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Sinks;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Pusher\ApiErrorException;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamStatus;

/**
 * Broadcasts each event on the stream's channel (`private-` plus the prefix, unless `streaming.broadcast.private` is
 * false). The store is written before this sink (WrappedStreamSink), so a broadcast failure loses nothing a reader
 * of the store needs, and it does not fail the run: a frame over `streaming.broadcast.max_bytes` less the header
 * headroom is skipped. On Pusher or Reverb a frame the server refuses with a 4xx code (413) is skipped too; a network
 * error (code 0), a 5xx or any other failure stops the sink for the run.
 * `streaming.broadcast.fatal` restores the old behavior: every frame is sent and an error fails the run.
 *
 * Text and thinking deltas are buffered and sent as one `batch` frame (`streaming.broadcast.batch_ms`, 0 = off).
 * Every send is a direct call, never queued: queued sends lose the order across queue workers.
 */
final class BroadcastSink implements StreamSinkInterface
{
    private const BATCHED = ['text_delta', 'thinking_delta'];

    /**
     * Room every frame leaves under max_bytes for the request line and headers: Reverb's max_request_size counts the
     * whole HTTP request, not the body. ponytail: fixed allowance, 361 bytes measured with the Pusher SDK on Reverb;
     * measure the real request if a client sends longer headers.
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
        $limit = self::bodyLimit();
        if ($this->batch !== [] && $limit !== null && $this->bytes('batch', $this->batchBody([...$this->batch, $item])) > $limit) {
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

    /** One buffered delta goes as a normal frame, two or more as one `batch` frame. A frame over the body limit is skipped by send(). */
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

    /** The largest request body for any frame: max_bytes minus the header headroom. Null: max_bytes is 0, no limit. */
    private static function bodyLimit(): ?int
    {
        $maxBytes = (int) config('agenticgraph.streaming.broadcast.max_bytes', 10000);

        return $maxBytes > 0 ? $maxBytes - self::HEADER_BYTES : null;
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
        $limit = self::bodyLimit();
        if ($limit !== null && $bytes > $limit) {
            Log::warning('agenticgraph.broadcast.frame_skipped', ['stream_id' => $this->streamId, 'event' => $event, 'bytes' => $bytes, 'max_bytes' => (int) config('agenticgraph.streaming.broadcast.max_bytes', 10000)]);

            return;
        }
        try {
            $this->trigger($channels, $event, $payload);
        } catch (\Throwable $e) {
            if ($e instanceof ApiErrorException && $e->getCode() >= 400 && $e->getCode() < 500) {
                // the server refused this frame (413: too large); the next one can pass, the replay heals this one
                Log::warning('agenticgraph.broadcast.frame_skipped', ['stream_id' => $this->streamId, 'event' => $event, 'code' => $e->getCode()]);

                return;
            }
            // ponytail: a network error (code 0), a 5xx or any other failure stops broadcasting for the run, a transient
            // one included; the store has it all.
            $this->down = true;
            Log::warning('agenticgraph.broadcast.stopped', ['stream_id' => $this->streamId, 'event' => $event, 'error' => $e::class, 'message' => preg_replace('/\?[^\s)]*/', '', $e->getMessage())]); // the URL query holds the app key
        }
    }

    /**
     * Pusher or Reverb: the call Laravel's PusherBroadcaster::broadcast() makes, without its wrap in BroadcastException,
     * so the status code stays. The request body is the same. Any other broadcaster: broadcast() as bound.
     *
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $payload
     */
    private function trigger(array $channels, string $event, array $payload): void
    {
        if (! $this->broadcaster instanceof PusherBroadcaster) {
            $this->broadcaster->broadcast($channels, $event, $payload);

            return;
        }
        $socket = Arr::pull($payload, 'socket');
        $this->broadcaster->getPusher()->trigger($channels, $event, $payload, $socket !== null ? ['socket_id' => $socket] : []);
    }
}
