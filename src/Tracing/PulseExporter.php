<?php

namespace Shahzad\AgenticGraph\Tracing;

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

        $payload = [
            'trace' => $this->traces[$traceId],
            'spans' => array_filter($this->spans, fn ($s) => ($s['trace_id'] ?? '') === $traceId),
            'llm_calls' => array_filter($this->llmCalls, fn ($c) => ($c['trace_id'] ?? '') === $traceId),
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
