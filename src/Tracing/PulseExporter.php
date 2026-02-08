<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Exports traces to AgenticPulse SaaS.
 * Buffers spans during execution and flushes asynchronously on completion.
 * Supports payload size management with automatic truncation.
 */
class PulseExporter
{
    /** @var array<string, array<string, mixed>> */
    private array $traces = [];

    /** @var array<int, array<string, mixed>> */
    private array $spans = [];

    /** @var array<int, array<string, mixed>> */
    private array $llmCalls = [];

    /** @var array<string, bool> */
    private array $sampledTraces = [];

    /** @var array<string, \Illuminate\Support\Carbon> */
    private array $traceStarts = [];

    /** Payload size limits */
    private const MAX_PAYLOAD_BYTES = 1_048_576; // 1MB

    private const HARD_PAYLOAD_LIMIT = 10_485_760; // 10MB - absolute maximum

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

    /**
     * @param  array<string, mixed>  $item
     */
    private function appendBuffered(string $traceId, string $type, array $item): void
    {
        $store = $this->bufferStore();
        $key = $this->bufferKey($traceId, $type);
        $ttl = $this->bufferTtlSeconds();
        $items = $store->get($key, []);
        $items = is_array($items) ? $items : [];
        $items[] = $item;
        $store->put($key, $items, $ttl);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function mergeById(array $items): array
    {
        $byId = [];
        $others = [];
        foreach ($items as $item) {
            if (array_key_exists('id', $item) && $item['id'] !== null) {
                $traceId = array_key_exists('trace_id', $item) ? (string) $item['trace_id'] : '';
                $byId[$traceId.':'.(string) $item['id']] = $item;
            } else {
                $others[] = $item;
            }
        }

        return array_values(array_merge($byId, $others));
    }

    /**
     * @param  array<string, mixed>  $span
     * @return array<string, mixed>
     */
    protected function normalizeSpanTimestamps(array $span): array
    {
        $durationMs = array_key_exists('duration_ms', $span) ? (float) $span['duration_ms'] : null;
        if (! $durationMs || $durationMs <= 0) {
            return $span;
        }

        $startedAt = $this->parseDateTime($span['started_at'] ?? null);
        $endedAt = $this->parseDateTime($span['ended_at'] ?? null);

        if ($startedAt) {
            $expectedEndedAt = $this->addDuration($startedAt, $durationMs);
            $span['ended_at'] = $expectedEndedAt->format(DATE_ATOM);

            return $span;
        }

        if ($endedAt) {
            $expectedStartedAt = $this->addDuration($endedAt, -$durationMs);
            $span['started_at'] = $expectedStartedAt->format(DATE_ATOM);
            $span['ended_at'] = $endedAt->format(DATE_ATOM);
        }

        return $span;
    }

    /**
     * @param  array<int, array<string, mixed>>  $spans
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeSpans(array $spans): array
    {
        return array_map(fn (array $span) => $this->normalizeSpanTimestamps($span), $spans);
    }

    /**
     * @param  array<string, mixed>  $trace
     * @param  array<int, array<string, mixed>>  $spans
     * @return array<string, mixed>
     */
    protected function normalizeTraceWindow(array $trace, array $spans): array
    {
        $traceStart = $this->parseDateTime($trace['started_at'] ?? null);
        if (! $traceStart) {
            return $trace;
        }

        // Preserve the original trace duration if already set (e.g., by completeTrace)
        // Only extend the trace window if spans fall outside the existing window
        $existingDuration = $trace['duration_ms'] ?? null;
        $existingEnd = $this->parseDateTime($trace['ended_at'] ?? null);

        // Find the max span end time
        $maxSpanEnd = null;
        foreach ($spans as $span) {
            $spanEnd = $this->parseDateTime($span['ended_at'] ?? null);
            if ($spanEnd && (! $maxSpanEnd || $spanEnd > $maxSpanEnd)) {
                $maxSpanEnd = $spanEnd;
            }
        }

        // Only update trace end if spans extend beyond the existing trace window
        // OR if no trace end is set yet
        if ($maxSpanEnd && $maxSpanEnd > $traceStart) {
            if (! $existingEnd || $maxSpanEnd > $existingEnd) {
                $trace['ended_at'] = $maxSpanEnd->format(DATE_ATOM);
                $trace['duration_ms'] = ($this->toFloatSeconds($maxSpanEnd) - $this->toFloatSeconds($traceStart)) * 1000;
            }
            // Otherwise, keep the existing trace end and duration (don't overwrite with span times)
        }

        return $trace;
    }

    /**
     * @param  array<string, mixed>  $trace
     * @param  array<int, array<string, mixed>>  $spans
     * @return array<int, array<string, mixed>>
     */
    protected function findSpansOutsideTraceWindow(array $trace, array $spans): array
    {
        $traceStart = $this->parseDateTime($trace['started_at'] ?? null);
        if (! $traceStart) {
            return [];
        }

        $traceEnd = $this->parseDateTime($trace['ended_at'] ?? null);
        if (! $traceEnd) {
            $durationMs = array_key_exists('duration_ms', $trace) ? (float) $trace['duration_ms'] : null;
            if ($durationMs && $durationMs > 0) {
                $traceEnd = $this->addDuration($traceStart, $durationMs);
            }
        }

        if (! $traceEnd) {
            return [];
        }

        $invalid = [];
        foreach ($spans as $span) {
            $spanStart = $this->parseDateTime($span['started_at'] ?? null);
            $spanEnd = $this->parseDateTime($span['ended_at'] ?? null);

            if (! $spanStart || ! $spanEnd) {
                $invalid[] = $span;

                continue;
            }

            if ($spanStart < $traceStart || $spanEnd > $traceEnd) {
                $invalid[] = $span;
            }
        }

        return $invalid;
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
     *
     * @param  array<int, string>  $initialStateKeys
     * @param  array<string, mixed>|null  $initialStateData
     */
    public function startTrace(
        string $traceId,
        string $graphName,
        array $initialStateKeys = [],
        ?\DateTimeImmutable $startedAt = null,
        ?array $initialStateData = null
    ): void {
        if (! $this->enabled) {
            return;
        }

        $sampled = $this->shouldSampleTrace();
        $this->sampledTraces[$traceId] = $sampled;
        $startTime = $startedAt ? Carbon::instance($startedAt) : now();
        $this->traceStarts[$traceId] = $startTime;

        $this->traces[$traceId] = [
            'id' => $traceId,
            'graph_name' => $graphName,
            'status' => 'running',
            'started_at' => $startTime->toIso8601String(),
            'initial_state_keys' => $initialStateKeys,
            'sampled' => $sampled,
            'resource' => [
                'service.name' => (string) config('agenticgraph.tracing.service.name', 'agenticgraph'),
                'service.version' => (string) config('agenticgraph.tracing.service.version', 'unknown'),
                'deployment.environment' => (string) config('agenticgraph.tracing.environment', 'local'),
            ],
            'instrumentation_scope' => (string) config('agenticgraph.tracing.instrumentation_scope', 'agenticgraph'),
        ];

        // Add initial state data if provided
        if ($initialStateData !== null && $initialStateData !== []) {
            $this->traces[$traceId]['initial_state'] = $initialStateData;
        }
    }

    public function getTraceStartTime(string $traceId): ?\Illuminate\Support\Carbon
    {
        return $this->traceStarts[$traceId] ?? null;
    }

    /**
     * Add a span to the current trace.
     */
    /**
     * @param  array<string, mixed>  $span
     */
    public function addSpan(array $span): void
    {
        if (! $this->enabled) {
            return;
        }

        $traceId = $span['trace_id'] ?? null;
        if (! $this->isSampled($traceId)) {
            return;
        }

        $this->spans[] = $span;
        if ($traceId) {
            $this->appendBuffered($traceId, 'spans', $span);
        }
    }

    /**
     * Add an LLM call record.
     */
    /**
     * @param  array<string, mixed>  $call
     */
    public function addLlmCall(array $call): void
    {
        if (! $this->enabled) {
            return;
        }

        $traceId = $call['trace_id'] ?? null;
        if (! $this->isSampled($traceId)) {
            return;
        }

        $this->llmCalls[] = $call;
        if ($traceId) {
            $this->appendBuffered($traceId, 'llm_calls', $call);
        }
    }

    /**
     * Complete a trace (success).
     *
     * @param  array<int, string>  $finalStateKeys
     * @param  array<string, mixed>|null  $finalStateData
     */
    public function completeTrace(
        string $traceId,
        array $finalStateKeys = [],
        float $durationMs = 0,
        ?array $finalStateData = null
    ): void {
        if (! $this->enabled || ! isset($this->traces[$traceId])) {
            return;
        }

        $this->traces[$traceId]['status'] = 'completed';
        $startedAt = $this->traceStarts[$traceId] ?? null;
        if ($startedAt) {
            $this->traces[$traceId]['ended_at'] = $startedAt
                ->copy()
                ->addMilliseconds((int) $durationMs)
                ->toIso8601String();
        } else {
            $this->traces[$traceId]['ended_at'] = now()->toIso8601String();
        }
        $this->traces[$traceId]['duration_ms'] = $durationMs;
        $this->traces[$traceId]['final_state_keys'] = $finalStateKeys;

        // Add final state data if provided
        if ($finalStateData !== null && $finalStateData !== []) {
            $this->traces[$traceId]['final_state'] = $finalStateData;
        }

        if (! $this->isSampled($traceId)) {
            $this->clearBufferedItems($traceId, 'spans');
            $this->clearBufferedItems($traceId, 'llm_calls');
            $this->reset();

            return;
        }

        $this->flush($traceId);
    }

    /**
     * Fail a trace (error).
     *
     * @param  array<string, mixed>|null  $finalStateData
     */
    public function failTrace(
        string $traceId,
        \Throwable $exception,
        float $durationMs = 0,
        ?array $finalStateData = null
    ): void {
        if (! $this->enabled || ! isset($this->traces[$traceId])) {
            return;
        }

        $this->traces[$traceId]['status'] = 'failed';
        $startedAt = $this->traceStarts[$traceId] ?? null;
        if ($startedAt) {
            $this->traces[$traceId]['ended_at'] = $startedAt
                ->copy()
                ->addMilliseconds((int) $durationMs)
                ->toIso8601String();
        } else {
            $this->traces[$traceId]['ended_at'] = now()->toIso8601String();
        }
        $this->traces[$traceId]['duration_ms'] = $durationMs;
        $this->traces[$traceId]['error'] = [
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
        ];

        // Add final state data if provided (especially useful for error debugging)
        if ($finalStateData !== null && $finalStateData !== []) {
            $this->traces[$traceId]['final_state'] = $finalStateData;
        }

        if (! $this->isSampled($traceId)) {
            if (! $this->shouldAlwaysSampleOnError()) {
                $this->clearBufferedItems($traceId, 'spans');
                $this->clearBufferedItems($traceId, 'llm_calls');
                $this->reset();

                return;
            }
        }

        $this->flush($traceId);
    }

    /**
     * Interrupt a trace (paused for human input).
     * Unlike fail/complete, this preserves the trace for continuation.
     */
    public function interruptTrace(string $traceId, string $reason): void
    {
        if (! $this->enabled || ! isset($this->traces[$traceId])) {
            return;
        }

        $this->traces[$traceId]['status'] = 'interrupted';
        $this->traces[$traceId]['interrupt_reason'] = $reason;
        $this->traces[$traceId]['interrupted_at'] = now()->toIso8601String();

        // Mark trace as interrupted but don't reset - it may be resumed
        // The trace stays in buffer with interrupted status
        if (! $this->isSampled($traceId)) {
            $this->clearBufferedItems($traceId, 'spans');
            $this->clearBufferedItems($traceId, 'llm_calls');
            $this->reset();

            return;
        }

        // Flush current progress so it's not lost during the interrupt
        $this->flush($traceId);
    }

    /**
     * Resume a previously interrupted trace.
     * Rehydrates trace state for continuation.
     */
    public function resumeTrace(string $traceId): void
    {
        if (! $this->enabled) {
            return;
        }

        // Mark trace as running again
        if (isset($this->traces[$traceId])) {
            $this->traces[$traceId]['status'] = 'running';
            $this->traces[$traceId]['resumed_at'] = now()->toIso8601String();
            unset($this->traces[$traceId]['interrupt_reason']);
            unset($this->traces[$traceId]['interrupted_at']);
        }
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

        $payload = $this->buildPayload(
            $traceId,
            $localSpans,
            $bufferedSpans,
            $localCalls,
            $bufferedCalls
        );

        $requestBound = app()->bound('request');
        $isHttpContext = $requestBound && (! app()->runningInConsole() || app()->runningUnitTests());

        if ($isHttpContext) {
            // In HTTP context, dispatch after response to avoid blocking
            dispatch(function () use ($payload) {
                try {
                    /** @var \Illuminate\Http\Client\Response $response */
                    $response = Http::withToken($this->apiKey)
                        ->timeout(5)
                        ->post("{$this->endpoint}/v1/ingest", $payload);

                    if ($response->failed()) {
                        if ((bool) config('agenticgraph.logging.enabled', true)) {
                            Log::warning('AgenticPulse ingest failed', [
                                'status' => $response->status(),
                                'body' => $response->body(),
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    // Silent fail - don't break the user's application
                    if ((bool) config('agenticgraph.logging.enabled', true)) {
                        Log::debug('AgenticPulse ingest error', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            })->afterResponse();
        } else {
            // In queue/console context, execute synchronously
            // This ensures traces are sent immediately when queue jobs complete
            try {
                /** @var \Illuminate\Http\Client\Response $response */
                $response = Http::withToken($this->apiKey)
                    ->timeout(5)
                    ->post("{$this->endpoint}/v1/ingest", $payload);

                if ($response->failed()) {
                    if ((bool) config('agenticgraph.logging.enabled', true)) {
                        Log::warning('AgenticPulse ingest failed', [
                            'status' => $response->status(),
                            'body' => $response->body(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                // Silent fail - don't break the user's application
                if ((bool) config('agenticgraph.logging.enabled', true)) {
                    Log::debug('AgenticPulse ingest error', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

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
        $this->sampledTraces = [];
        $this->traceStarts = [];
    }

    /**
     * Get the current buffer (for testing).
     *
     * @return array<string, mixed>
     */
    public function getBuffer(): array
    {
        return [
            'traces' => $this->traces,
            'spans' => $this->spans,
            'llm_calls' => $this->llmCalls,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $localSpans
     * @param  array<int, array<string, mixed>>  $bufferedSpans
     * @param  array<int, array<string, mixed>>  $localCalls
     * @param  array<int, array<string, mixed>>  $bufferedCalls
     * @return array<string, mixed>
     */
    protected function buildPayload(
        string $traceId,
        array $localSpans,
        array $bufferedSpans,
        array $localCalls,
        array $bufferedCalls
    ): array {
        $spans = $this->mergeById(array_merge($localSpans, $bufferedSpans));
        $spans = $this->normalizeSpans($spans);
        $trace = $this->normalizeTraceWindow($this->traces[$traceId], $spans);

        $llmCalls = $this->mergeById(array_merge($localCalls, $bufferedCalls));

        $payload = [
            'trace' => $trace,
            'spans' => $spans,
            'llm_calls' => $llmCalls,
        ];

        // Check and manage payload size
        return $this->preparePayload($payload);
    }

    /**
     * Prepare payload with size management and truncation.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function preparePayload(array $payload): array
    {
        $estimatedSize = $this->estimatePayloadSize($payload);

        // If under limit, return as-is
        if ($estimatedSize < self::MAX_PAYLOAD_BYTES) {
            return $payload;
        }

        // If over hard limit, create error trace instead
        if ($estimatedSize > self::HARD_PAYLOAD_LIMIT) {
            return $this->createErrorPayload($payload, 'Payload exceeded maximum size limit');
        }

        // Apply smart truncation
        return $this->smartTruncatePayload($payload, $estimatedSize);
    }

    /**
     * Estimate payload size without full JSON encoding.
     *
     * @param  array<string, mixed>  $payload
     */
    private function estimatePayloadSize(array $payload): int
    {
        $size = 0;

        // Estimate trace size
        if (isset($payload['trace'])) {
            $size += $this->estimateArraySize($payload['trace']);
        }

        // Estimate spans size
        if (isset($payload['spans'])) {
            foreach ($payload['spans'] as $span) {
                $size += $this->estimateArraySize($span);
            }
        }

        // Estimate LLM calls size (these often contain large text)
        if (isset($payload['llm_calls'])) {
            foreach ($payload['llm_calls'] as $call) {
                $size += $this->estimateArraySize($call);
            }
        }

        return $size;
    }

    /**
     * Estimate size of an array without JSON encoding.
     *
     * @param  array<int|string, mixed>  $data
     */
    private function estimateArraySize(array $data): int
    {
        $size = 0;
        foreach ($data as $key => $value) {
            $size += strlen((string) $key);
            if (is_string($value)) {
                $size += strlen($value);
            } elseif (is_array($value)) {
                $size += $this->estimateArraySize($value);
            } elseif (is_object($value)) {
                $encoded = json_encode($value);
                $size += $encoded !== false ? strlen($encoded) : 100;
            } else {
                $size += strlen(json_encode($value) ?: '');
            }
        }

        return $size;
    }

    /**
     * Smart truncate payload to fit within size limits.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function smartTruncatePayload(array $payload, int $currentSize): array
    {
        $targetSize = (int) (self::MAX_PAYLOAD_BYTES * 0.9); // Aim for 90% of limit
        $excess = $currentSize - $targetSize;
        $applied = [];

        // Priority 1: Truncate LLM responses
        if ($excess > 0 && isset($payload['llm_calls'])) {
            $payload['llm_calls'] = $this->truncateLlmResponses($payload['llm_calls'], $excess);
            $applied[] = 'responses';
            $newSize = $this->estimatePayloadSize($payload);
            if ($newSize <= self::MAX_PAYLOAD_BYTES) {
                return $this->finalizePayload($payload, $applied, $currentSize);
            }
            $excess = $newSize - $targetSize;
        }

        // Priority 2: Truncate LLM prompts
        if ($excess > 0 && isset($payload['llm_calls'])) {
            $payload['llm_calls'] = $this->truncateLlmPrompts($payload['llm_calls'], $excess);
            $applied[] = 'prompts';
            $newSize = $this->estimatePayloadSize($payload);
            if ($newSize <= self::MAX_PAYLOAD_BYTES) {
                return $this->finalizePayload($payload, $applied, $currentSize);
            }
            $excess = $newSize - $targetSize;
        }

        // Priority 3: Drop middle spans (keep first 50 + last 50)
        if ($excess > 0 && isset($payload['spans']) && count($payload['spans']) > 100) {
            $payload['spans'] = array_merge(
                array_slice($payload['spans'], 0, 50),
                array_slice($payload['spans'], -50)
            );
            $applied[] = 'spans';
            $newSize = $this->estimatePayloadSize($payload);
            if ($newSize <= self::MAX_PAYLOAD_BYTES) {
                return $this->finalizePayload($payload, $applied, $currentSize);
            }
        }

        // Priority 4: Remove state values from trace (keep only keys)
        if (isset($payload['trace'])) {
            unset($payload['trace']['initial_state']);
            unset($payload['trace']['final_state']);
            $applied[] = 'state_values';
        }

        return $this->finalizePayload($payload, $applied, $currentSize);
    }

    /**
     * Truncate LLM response text in calls.
     *
     * @param  array<int, array<string, mixed>>  $calls
     * @return array<int, array<string, mixed>>
     */
    private function truncateLlmResponses(array $calls, int $targetReduction): array
    {
        $responseLengths = [];
        foreach ($calls as $index => $call) {
            if (isset($call['response']) && is_string($call['response'])) {
                $responseLengths[$index] = strlen($call['response']);
            }
        }

        if ($responseLengths === []) {
            return $calls;
        }

        // Sort by length (longest first) to truncate biggest first
        arsort($responseLengths);

        $reduced = 0;
        foreach ($responseLengths as $index => $length) {
            if ($reduced >= $targetReduction) {
                break;
            }

            $currentResponse = $calls[$index]['response'];
            $targetLength = (int) ($length * 0.5); // Reduce by 50%

            $calls[$index]['response'] = $this->truncateText($currentResponse, $targetLength);
            $reduced += ($length - $targetLength);
        }

        return $calls;
    }

    /**
     * Truncate LLM prompt text in calls.
     *
     * @param  array<int, array<string, mixed>>  $calls
     * @return array<int, array<string, mixed>>
     */
    private function truncateLlmPrompts(array $calls, int $targetReduction): array
    {
        $promptLengths = [];
        foreach ($calls as $index => $call) {
            if (isset($call['prompt']) && is_string($call['prompt'])) {
                $promptLengths[$index] = strlen($call['prompt']);
            }
        }

        if ($promptLengths === []) {
            return $calls;
        }

        // Sort by length (longest first) to truncate biggest first
        arsort($promptLengths);

        $reduced = 0;
        foreach ($promptLengths as $index => $length) {
            if ($reduced >= $targetReduction) {
                break;
            }

            $currentPrompt = $calls[$index]['prompt'];
            $targetLength = (int) ($length * 0.5); // Reduce by 50%

            $calls[$index]['prompt'] = $this->truncateText($currentPrompt, $targetLength);
            $reduced += ($length - $targetLength);
        }

        return $calls;
    }

    /**
     * Truncate text to specified length with ellipsis.
     */
    private function truncateText(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        // Try to truncate at sentence boundary
        $truncated = substr($text, 0, $maxLength - 20);
        $lastSentence = strrpos($truncated, '. ');

        if ($lastSentence !== false && $lastSentence > $maxLength * 0.7) {
            return substr($truncated, 0, $lastSentence + 1).' [...truncated]';
        }

        // Fallback: truncate at word boundary
        $lastSpace = strrpos($truncated, ' ');
        if ($lastSpace !== false) {
            return substr($truncated, 0, $lastSpace).' [...truncated]';
        }

        // Final fallback: hard truncate
        return substr($text, 0, $maxLength - 15).' [...truncated]';
    }

    /**
     * Finalize payload with truncation metadata.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $applied
     * @return array<string, mixed>
     */
    private function finalizePayload(array $payload, array $applied, int $originalSize): array
    {
        if ($applied === []) {
            return $payload;
        }

        // Add truncation metadata
        $payload['_meta'] = [
            'truncated' => true,
            'truncation_applied' => array_values(array_unique($applied)),
            'original_size_bytes' => $originalSize,
            'truncated_at' => now()->toIso8601String(),
        ];

        return $payload;
    }

    /**
     * Create an error payload when trace is too large.
     *
     * @param  array<string, mixed>  $originalPayload
     * @return array<string, mixed>
     */
    private function createErrorPayload(array $originalPayload, string $reason): array
    {
        $trace = $originalPayload['trace'] ?? [];

        return [
            'trace' => [
                'id' => $trace['id'] ?? 'unknown',
                'graph_name' => $trace['graph_name'] ?? 'unknown',
                'status' => 'error',
                'error_reason' => $reason,
                'started_at' => $trace['started_at'] ?? now()->toIso8601String(),
                'resource' => $trace['resource'] ?? [],
            ],
            'spans' => [],
            'llm_calls' => [],
            '_meta' => [
                'truncated' => true,
                'truncation_applied' => ['complete_payload'],
                'error' => 'Payload size exceeded 10MB limit',
            ],
        ];
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function addDuration(\DateTimeImmutable $startedAt, float $durationMs): \DateTimeImmutable
    {
        $durationSeconds = $durationMs / 1000;

        // Use DateInterval instead of modify() to properly handle fractional seconds
        // PHP's modify() ignores decimal points (e.g., "0.082563" becomes "82563" seconds)
        $wholeSeconds = intval($durationSeconds);
        $fractionalSeconds = $durationSeconds - $wholeSeconds;

        $interval = new \DateInterval("PT{$wholeSeconds}S");
        if ($fractionalSeconds > 0) {
            $interval->f = $fractionalSeconds;
        }

        return $startedAt->add($interval);
    }

    private function toFloatSeconds(\DateTimeImmutable $time): float
    {
        return (float) $time->format('U.u');
    }

    private function shouldSampleTrace(): bool
    {
        $rate = (float) config('agenticgraph.tracing.sampling.rate', 1.0);
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return mt_rand() / mt_getrandmax() < $rate;
    }

    private function isSampled(?string $traceId): bool
    {
        if (! $traceId) {
            return false;
        }

        return $this->sampledTraces[$traceId] ?? true;
    }

    private function shouldAlwaysSampleOnError(): bool
    {
        return (bool) config('agenticgraph.tracing.sampling.always_on_error', true);
    }
}
