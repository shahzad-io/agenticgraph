<?php

namespace Shahzad\AgenticGraph\Tracing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Exports traces to AgenticPulse SaaS.
 * Buffers spans during execution and flushes asynchronously on completion.
 */
class PulseExporter
{
    private array $traces = [];

    private array $spans = [];

    private array $llmCalls = [];

    private ?string $endpoint = null;

    private ?string $apiKey = null;

    private bool $enabled = false;

    public function __construct()
    {
        $this->endpoint = config('agenticgraph.tracing.endpoint');
        $this->apiKey = config('agenticgraph.tracing.api_key');
        $this->enabled = config('agenticgraph.tracing.enabled', false) && ! empty($this->apiKey);
    }

    private function bufferStore(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('agenticgraph.tracing.buffer.store');

        return $store ? Cache::store($store) : Cache::store();
    }

    private function bufferTtlSeconds(): int
    {
        return (int) config('agenticgraph.tracing.buffer.ttl_seconds', 3600);
    }

    private function bufferKey(string $traceId, string $type): string
    {
        return "agenticpulse:trace:{$traceId}:{$type}";
    }

    private function appendBuffered(string $traceId, string $type, array $item): void
    {
        $store = $this->bufferStore();
        $key = $this->bufferKey($traceId, $type);
        $ttl = $this->bufferTtlSeconds();
        $items = $store->get($key, []);
        if (! is_array($items)) {
            $items = [];
        }
        $items[] = $item;
        $store->put($key, $items, $ttl);
    }

    private function getBufferedItems(string $traceId, string $type): array
    {
        $store = $this->bufferStore();
        $items = $store->get($this->bufferKey($traceId, $type), []);

        return is_array($items) ? $items : [];
    }

    private function clearBufferedItems(string $traceId, string $type): void
    {
        $store = $this->bufferStore();
        $store->forget($this->bufferKey($traceId, $type));
    }

    private function mergeById(array $items): array
    {
        $byId = [];
        $others = [];
        foreach ($items as $item) {
            if (is_array($item) && array_key_exists('id', $item) && $item['id'] !== null) {
                $byId[(string) $item['id']] = $item;
            } else {
                $others[] = $item;
            }
        }

        return array_values(array_merge($byId, $others));
    }

    /**
     * Check if the exporter is configured and enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Start a new trace.
     */
    public function startTrace(string $traceId, string $graphName, array $initialStateKeys = []): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->traces[$traceId] = [
            'id' => $traceId,
            'graph_name' => $graphName,
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
            'initial_state_keys' => $initialStateKeys,
        ];
    }

    /**
     * Add a span to the current trace.
     */
    public function addSpan(array $span): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->spans[] = $span;
        $traceId = $span['trace_id'] ?? null;
        if ($traceId) {
            $this->appendBuffered($traceId, 'spans', $span);
        }
    }

    /**
     * Add an LLM call record.
     */
    public function addLlmCall(array $call): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->llmCalls[] = $call;
        $traceId = $call['trace_id'] ?? null;
        if ($traceId) {
            $this->appendBuffered($traceId, 'llm_calls', $call);
        }
    }

    /**
     * Complete a trace (success).
     */
    public function completeTrace(string $traceId, array $finalStateKeys = [], float $durationMs = 0): void
    {
        if (! $this->enabled || ! isset($this->traces[$traceId])) {
            return;
        }

        $this->traces[$traceId]['status'] = 'completed';
        $this->traces[$traceId]['ended_at'] = now()->toIso8601String();
        $this->traces[$traceId]['duration_ms'] = $durationMs;
        $this->traces[$traceId]['final_state_keys'] = $finalStateKeys;

        $this->flush($traceId);
    }

    /**
     * Fail a trace (error).
     */
    public function failTrace(string $traceId, \Throwable $exception, float $durationMs = 0): void
    {
        if (! $this->enabled || ! isset($this->traces[$traceId])) {
            return;
        }

        $this->traces[$traceId]['status'] = 'failed';
        $this->traces[$traceId]['ended_at'] = now()->toIso8601String();
        $this->traces[$traceId]['duration_ms'] = $durationMs;
        $this->traces[$traceId]['error'] = [
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
        ];

        $this->flush($traceId);
    }

    /**
     * Flush buffered data to AgenticPulse API.
     */
    public function flush(string $traceId): void
    {
        if (! $this->enabled || empty($this->traces[$traceId])) {
            $this->reset();

            return;
        }

        $localSpans = array_filter($this->spans, fn ($s) => ($s['trace_id'] ?? '') === $traceId);
        $localCalls = array_filter($this->llmCalls, fn ($c) => ($c['trace_id'] ?? '') === $traceId);
        $bufferedSpans = $this->getBufferedItems($traceId, 'spans');
        $bufferedCalls = $this->getBufferedItems($traceId, 'llm_calls');

        $payload = [
            'trace' => $this->traces[$traceId],
            'spans' => $this->mergeById(array_merge($localSpans, $bufferedSpans)),
            'llm_calls' => $this->mergeById(array_merge($localCalls, $bufferedCalls)),
        ];

        // Dispatch async to avoid blocking the response
        dispatch(function () use ($payload) {
            try {
                $response = Http::withToken($this->apiKey)
                    ->timeout(5)
                    ->post("{$this->endpoint}/v1/ingest", $payload);

                if ($response->failed()) {
                    Log::warning('AgenticPulse ingest failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                // Silent fail - don't break the user's application
                Log::debug('AgenticPulse ingest error', [
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();

        $this->clearBufferedItems($traceId, 'spans');
        $this->clearBufferedItems($traceId, 'llm_calls');
        $this->reset();
    }

    /**
     * Reset the buffer.
     */
    public function reset(): void
    {
        $this->traces = [];
        $this->spans = [];
        $this->llmCalls = [];
    }

    /**
     * Get the current buffer (for testing).
     */
    public function getBuffer(): array
    {
        return [
            'traces' => $this->traces,
            'spans' => $this->spans,
            'llm_calls' => $this->llmCalls,
        ];
    }
}
