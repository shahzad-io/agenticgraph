<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Http\Controllers;

use Illuminate\Http\Request;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StreamController
{
    public function stream(string $streamId, Request $request, StreamStoreInterface $store, ?ExecutionStore $executionStore = null): StreamedResponse
    {
        $executionStore ??= app(ExecutionStore::class);
        $cursor = $request->query('cursor', $request->header('Last-Event-ID'));
        $fromSeq = is_numeric($cursor) ? ((int) $cursor + 1) : 1;

        return response()->stream(function () use ($streamId, $request, $store, $executionStore, $cursor, $fromSeq): void {
            if (! app()->runningUnitTests()) {
                @ini_set('output_buffering', 'off');
                @ini_set('zlib.output_compression', '0');
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                ob_implicit_flush(true);
            }
            $maxDuration = (int) config('agenticgraph.streaming.sse.max_duration_seconds', 120);
            $pollIntervalMs = (int) config('agenticgraph.streaming.sse.poll_interval_ms', 250);
            $pollIntervalUs = max(1000, $pollIntervalMs * 1000);
            $heartbeatInterval = (int) config('agenticgraph.streaming.sse.heartbeat_interval', 30);
            $start = microtime(true);
            $nextHeartbeat = $heartbeatInterval > 0 ? $start + $heartbeatInterval : null;
            $lastEventSeq = $fromSeq;
            $replayNotified = false;
            $terminalType = StreamEventEnvelope::TYPE_EXECUTION_TERMINAL;

            while (microtime(true) - $start < $maxDuration) {
                $hasEvents = false;
                foreach ($store->readFrom($streamId, $lastEventSeq, 200) as $event) {
                    $hasEvents = true;
                    $lastEventSeq = $event->seq() + 1;
                    echo $this->formatEvent($event);
                    if ($event->type() === $terminalType) {
                        $this->flushOutput();

                        return;
                    }
                }
                if ($hasEvents) {
                    $nextHeartbeat = $heartbeatInterval > 0 ? microtime(true) + $heartbeatInterval : null;
                    $this->flushOutput();

                    continue;
                }

                if ($cursor !== null && $replayNotified === false && microtime(true) - $start >= 1.0) {
                    $payload = ['cursor' => $cursor];
                    $executionId = (string) $request->query('execution_id', '');
                    if ($executionId !== '') {
                        $record = $executionStore->get($executionId);
                        if ($record?->finalState !== null) {
                            $payload['final_state'] = $record->finalState;
                        }
                    }
                    echo $this->formatEvent(new StreamEventImpl(
                        $streamId,
                        $executionId,
                        null,
                        null,
                        null,
                        'stream_replay_unavailable',
                        $payload,
                        $lastEventSeq,
                        null,
                        new \DateTimeImmutable('now')
                    ));
                    $replayNotified = true;
                    $this->flushOutput();
                }

                if ($nextHeartbeat !== null && microtime(true) >= $nextHeartbeat) {
                    $payload = ['timestamp' => now()->toISOString()];
                    echo $this->formatRaw(null, 'stream_heartbeat', $payload);
                    $nextHeartbeat = microtime(true) + $heartbeatInterval;
                    $this->flushOutput();
                }

                usleep($pollIntervalUs);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Content-Encoding' => 'none',
        ]);
    }

    private function formatEvent(StreamEventImpl $event): string
    {
        $envelope = StreamEventEnvelope::fromStreamEvent($event);

        return $envelope->formatSse();
    }

    private function formatRaw(?string $id, string $event, mixed $data): string
    {
        return StreamEventEnvelope::formatSseRaw($id, $event, is_array($data) ? $data : ['payload' => $data]);
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
