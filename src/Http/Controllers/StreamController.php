<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Http\Controllers;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\AgenticGraph;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamTerminalStatus;
use Shahzad\AgenticGraph\Streaming\StreamToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE reader, JSON replay and channel auth over the stream store. All three check the stream token, then the app
 * authorizer (authorizeStream()).
 *
 * Stored events carry `id: {seq}`. Control events (heartbeat, reconnect, replay_unavailable)
 * carry no id and no seq, are never stored, and have a `control` key in the body.
 * An `interrupted` terminal keeps the connection open for the resumed segment; a
 * `completed` or `failed` terminal closes it. At max duration the server sends control
 * `reconnect` and closes.
 */
final class StreamController
{
    private const REPLAY_PAGE = 200;

    public function stream(string $streamId, Request $request, StreamStoreInterface $store, ?ExecutionStore $executionStore = null): StreamedResponse
    {
        $executionId = $this->ownerId($request, $this->authorizeStream($request, $streamId, $request->query('token')));
        $executionStore ??= app(ExecutionStore::class);
        $cursor = $request->query('cursor', $request->header('Last-Event-ID'));
        $cursor = is_numeric($cursor) ? (int) $cursor : null;
        $wrapCustom = $request->query('custom_events') === 'wrap';

        return response()->stream(function () use ($streamId, $executionId, $store, $executionStore, $cursor, $wrapCustom): void {
            if (! app()->runningUnitTests()) {
                @ini_set('output_buffering', 'off');
                @ini_set('zlib.output_compression', '0');
                // End only PHP's own buffers (output_buffering, zlib). A handler the server or the app owns stays:
                // Octane with Swoole writes the response through one, and ending it sent every event to stdout.
                while (ob_get_level() > 0 && in_array(ob_get_status()['name'] ?? '', ['default output handler', 'zlib output compression'], true)) {
                    ob_end_flush();
                }
                ob_implicit_flush(true);
            }
            $maxDuration = (float) config('agenticgraph.streaming.sse.max_duration_seconds', 120);
            $pollIntervalMs = (int) config('agenticgraph.streaming.sse.poll_interval_ms', 100);
            $pollIntervalUs = max(1000, $pollIntervalMs * 1000);
            $heartbeatInterval = (int) config('agenticgraph.streaming.sse.heartbeat_interval', 30);
            $retryMs = (int) config('agenticgraph.streaming.sse.retry_ms', 2000);
            $start = microtime(true);
            $nextHeartbeat = $heartbeatInterval > 0 ? $start + $heartbeatInterval : null;
            $nextSeq = $cursor === null ? 1 : $cursor + 1;

            echo 'retry: '.$retryMs."\n\n";

            if ($cursor !== null && $this->cursorIsGone($store, $streamId, $cursor)) {
                $payload = ['cursor' => $cursor];
                $finalState = $this->ownedFinalState($executionStore, $streamId, $executionId);
                if ($finalState !== false) {
                    $payload['final_state'] = $finalState;
                }
                echo $this->formatControl('replay_unavailable', $payload);
                $this->flushOutput();

                return;
            }

            $this->releaseDatabaseConnections();

            while (microtime(true) - $start < $maxDuration) {
                $hasEvents = false;
                foreach ($store->readFrom($streamId, $nextSeq, 200) as $event) {
                    $hasEvents = true;
                    $nextSeq = $event->seq() + 1;
                    echo $this->formatEvent($event, $wrapCustom);
                    if ($this->closesResponse($event)) {
                        $this->flushOutput();

                        return;
                    }
                }
                if ($hasEvents) {
                    $nextHeartbeat = $heartbeatInterval > 0 ? microtime(true) + $heartbeatInterval : null;
                    $this->flushOutput();

                    continue;
                }

                if ($nextHeartbeat !== null && microtime(true) >= $nextHeartbeat) {
                    echo $this->formatControl('heartbeat', ['timestamp' => now()->toISOString()]);
                    $nextHeartbeat = microtime(true) + $heartbeatInterval;
                    $this->flushOutput();
                }

                usleep($pollIntervalUs);
            }

            echo $this->formatControl('reconnect', ['after_ms' => $retryMs]);
            $this->flushOutput();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Content-Encoding' => 'none',
        ]);
    }

    /**
     * JSON replay of the stored events after `after`, one page of 200. Same cursor, gone and ownership rules as the
     * SSE route. One read, never a stream: a broadcast client heals a drop or a late join with it.
     */
    public function events(string $streamId, Request $request, StreamStoreInterface $store, ExecutionStore $executionStore): JsonResponse
    {
        $executionId = $this->ownerId($request, $this->authorizeStream($request, $streamId, $request->query('token')));
        $after = $request->query('after');
        $after = is_numeric($after) ? max(0, (int) $after) : null;

        if ($after !== null && $this->cursorIsGone($store, $streamId, $after)) {
            $response = ['events' => [], 'last_seq' => $after, 'more' => false, 'gone' => true];
            $finalState = $this->ownedFinalState($executionStore, $streamId, $executionId);
            if ($finalState !== false && $finalState !== null) {
                $response['final_state'] = $finalState;
            }

            return response()->json($response);
        }

        $events = [];
        $lastSeq = $after ?? 0;
        foreach ($store->readFrom($streamId, $lastSeq + 1, self::REPLAY_PAGE) as $event) {
            $envelope = StreamEventEnvelope::fromStreamEvent($event);
            $events[] = ['type' => $envelope->type, 'body' => $envelope->toWireArray()];
            $lastSeq = $event->seq();
        }

        return response()->json(['events' => $events, 'last_seq' => $lastSeq, 'more' => count($events) === self::REPLAY_PAGE, 'gone' => false]);
    }

    /**
     * Channel auth for a stream's private channel. Not Broadcast::auth(): that needs a user, and a guest with a valid
     * token has none. Signs with the Pusher client of the app's broadcaster (Pusher or Reverb only).
     */
    public function auth(string $streamId, Request $request, Broadcaster $broadcaster): JsonResponse
    {
        $this->authorizeStream($request, $streamId, $request->post('token'));
        if ($request->input('channel_name') !== BroadcastSink::privateChannelName($streamId)) {
            return response()->json(['error' => 'stream_channel_mismatch'], 403);
        }
        $socketId = $request->input('socket_id');
        if (! is_string($socketId) || preg_match('/\A\d+\.\d+\z/', $socketId) !== 1) {
            return response()->json(['error' => 'invalid_socket_id'], 422);
        }
        if (! $broadcaster instanceof PusherBroadcaster) {
            return response()->json(['error' => 'broadcaster_unsupported'], 501);
        }

        return JsonResponse::fromJsonString($broadcaster->getPusher()->authorizeChannel(BroadcastSink::privateChannelName($streamId), $socketId));
    }

    /**
     * The stream token first (`streaming.token.required`), then the app authorizer if set; both must pass. The caller
     * passes the token from the one place its route reads it (SSE and replay: query; channel auth: POST body).
     * Returns the execution id in a valid token, else null.
     */
    private function authorizeStream(Request $request, string $streamId, mixed $token): ?string
    {
        $result = is_string($token) && $token !== '' ? StreamToken::verify($token, $streamId) : null;
        if ((bool) config('agenticgraph.streaming.token.required', true) && ! $result?->isValid()) {
            throw new HttpResponseException(response()->json(['error' => $result?->state === 'expired' ? 'stream_token_expired' : 'stream_token_invalid'], 403));
        }
        $executionId = $result?->isValid() ? $result->executionId : null;

        $authorizer = app()->bound(AgenticGraph::STREAM_AUTHORIZER) ? app(AgenticGraph::STREAM_AUTHORIZER) : null;
        if ($authorizer instanceof \Closure && $authorizer($request, $streamId, $executionId) !== true) {
            throw new HttpResponseException(response()->json(['error' => 'stream_forbidden'], 403));
        }

        return $executionId;
    }

    /** The execution id for the final_state ownership check: the token's, else the `execution_id` query. */
    private function ownerId(Request $request, ?string $tokenExecutionId): ?string
    {
        $query = $request->query('execution_id');

        return $tokenExecutionId ?? (is_string($query) && $query !== '' ? $query : null);
    }

    /**
     * The client final state of the execution, only when it owns this stream: another run's id must not read its
     * state. False when it does not apply; null when final state is not exposed.
     *
     * @return array<string, mixed>|false|null
     */
    private function ownedFinalState(ExecutionStore $executionStore, string $streamId, ?string $executionId): array|false|null
    {
        $record = $executionId === null ? null : $executionStore->get($executionId);
        if ($record?->finalState === null || $record->streamId !== $streamId) {
            return false;
        }

        return StreamEventEnvelope::clientFinalState($record->finalState);
    }

    /**
     * The connect-time checks (route middleware, the execution lookup) are done, and the loop can wait for minutes.
     * Close every open connection so an idle stream does not hold a database slot. A later query reconnects (the
     * database stream store does on its first read). An in-memory SQLite connection is kept: closing it erases it.
     * A connection with an open transaction is kept: closing it rolls the transaction back.
     */
    private function releaseDatabaseConnections(): void
    {
        foreach (DB::getConnections() as $name => $connection) {
            if ($connection->getConfig('database') === ':memory:') {
                continue;
            }
            if ($connection->transactionLevel() > 0) {
                Log::debug('agenticgraph.stream.connection_kept', ['connection' => $name, 'reason' => 'open_transaction']);

                continue;
            }
            DB::disconnect($name);
        }
    }

    /**
     * Stores only drop events from the front (retention, max length, TTL), so a cursor is
     * gone when the next event it needs is older than the first stored one.
     */
    private function cursorIsGone(StreamStoreInterface $store, string $streamId, int $cursor): bool
    {
        $firstSeq = $store->firstSeq($streamId);

        return $firstSeq === null ? $cursor > 0 : $cursor + 1 < $firstSeq;
    }

    private function closesResponse(StreamEvent $event): bool
    {
        if ($event->type() !== StreamEventEnvelope::TYPE_EXECUTION_TERMINAL) {
            return false;
        }
        $payload = $event->payload();

        return ! is_array($payload) || ($payload['status'] ?? null) !== StreamTerminalStatus::INTERRUPTED->value;
    }

    private function formatEvent(StreamEvent $event, bool $wrapCustom = false): string
    {
        $envelope = StreamEventEnvelope::fromStreamEvent($event);

        return $envelope->formatSse(wrapCustom: $wrapCustom);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatControl(string $control, array $data): string
    {
        return StreamEventEnvelope::formatSseRaw(null, 'stream_'.$control, ['control' => $control] + $data);
    }

    private function flushOutput(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        if (function_exists('ob_get_level') && ob_get_level() > 0 && function_exists('ob_flush')) {
            ob_flush();
        }
        if (function_exists('flush')) {
            flush();
        }
    }
}
