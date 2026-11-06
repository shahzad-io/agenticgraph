<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Http\Controllers;

use Illuminate\Http\Request;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamTerminalStatus;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE reader over the stream store.
 *
 * Stored events carry `id: {seq}`. Control events (heartbeat, reconnect, replay_unavailable)
 * carry no id and no seq, are never stored, and have a `control` key in the body.
 * An `interrupted` terminal keeps the connection open for the resumed segment; a
 * `completed` or `failed` terminal closes it. At max duration the server sends control
 * `reconnect` and closes.
 */
final class StreamController
{
    public function stream(string $streamId, Request $request, StreamStoreInterface $store, ?ExecutionStore $executionStore = null): StreamedResponse
    {
        $executionStore ??= app(ExecutionStore::class);
        $cursor = $request->query('cursor', $request->header('Last-Event-ID'));
        $cursor = is_numeric($cursor) ? (int) $cursor : null;
        $wrapCustom = $request->query('custom_events') === 'wrap';

        return response()->stream(function () use ($streamId, $request, $store, $executionStore, $cursor, $wrapCustom): void {
            if (! app()->runningUnitTests()) {
                @ini_set('output_buffering', 'off');
                @ini_set('zlib.output_compression', '0');
                while (ob_get_level() > 0) {
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
                $executionId = (string) $request->query('execution_id', '');
                if ($executionId !== '') {
                    $record = $executionStore->get($executionId);
                    if ($record?->finalState !== null) {
                        $payload['final_state'] = StreamEventEnvelope::clientFinalState($record->finalState);
                    }
                }
                echo $this->formatControl('replay_unavailable', $payload);
                $this->flushOutput();

                return;
            }

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
