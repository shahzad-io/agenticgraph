<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

/**
 * Builds the trace envelope shared by every JSON exporter (pulse, file, log).
 * Merges re-sent spans by id, fills missing timestamps, extends the trace window,
 * and keeps the payload within the size limits.
 */
final class TracePayloadBuilder
{
    public const SCHEMA_VERSION = 'agenticgraph.trace.v1';

    /** Payload size limits */
    private const MAX_PAYLOAD_BYTES = 1_048_576; // 1MB

    private const HARD_PAYLOAD_LIMIT = 10_485_760; // 10MB - absolute maximum

    /**
     * @param  array<string, mixed>  $trace
     * @param  array<int, array<string, mixed>>  $spans
     * @param  array<int, array<string, mixed>>  $llmCalls
     * @return array<string, mixed>
     */
    public function build(array $trace, array $spans, array $llmCalls): array
    {
        $spans = $this->normalizeSpans($this->mergeById($spans));

        return $this->preparePayload([
            'schema_version' => self::SCHEMA_VERSION,
            'trace' => $this->normalizeTraceWindow($trace, $spans),
            'spans' => $spans,
            'llm_calls' => $this->mergeById($llmCalls),
        ]);
    }

    /**
     * @param  array<string, mixed>  $span
     * @return array<string, mixed>
     */
    private function normalizeSpanTimestamps(array $span): array
    {
        $durationMs = max((float) ($span['duration_ms'] ?? 0), 0.0);
        $startedAt = TraceTime::parse($span['started_at'] ?? null);
        $endedAt = TraceTime::parse($span['ended_at'] ?? null);

        // Timestamps are formatted once at the source; only fill a missing side.
        if ($startedAt && ! $endedAt) {
            $span['ended_at'] = TraceTime::format(TraceTime::addMs($startedAt, $durationMs));
        } elseif ($endedAt && ! $startedAt) {
            $span['started_at'] = TraceTime::format(TraceTime::addMs($endedAt, -$durationMs));
        }

        return $span;
    }

    /**
     * @param  array<int, array<string, mixed>>  $spans
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSpans(array $spans): array
    {
        return array_map(fn (array $span) => $this->normalizeSpanTimestamps($span), $spans);
    }

    /**
     * @param  array<string, mixed>  $trace
     * @param  array<int, array<string, mixed>>  $spans
     * @return array<string, mixed>
     */
    private function normalizeTraceWindow(array $trace, array $spans): array
    {
        $traceStart = TraceTime::parse($trace['started_at'] ?? null);
        if (! $traceStart) {
            return $trace;
        }

        // duration_ms comes from raw microtime (completeTrace, failTrace, interruptTrace) and is
        // never recomputed here. Only extend ended_at if spans fall outside the existing window.
        $existingEnd = TraceTime::parse($trace['ended_at'] ?? null);

        // Find the max span end time
        $maxSpanEnd = null;
        foreach ($spans as $span) {
            $spanEnd = TraceTime::parse($span['ended_at'] ?? null);
            if ($spanEnd && (! $maxSpanEnd || $spanEnd > $maxSpanEnd)) {
                $maxSpanEnd = $spanEnd;
            }
        }

        // Only update trace end if spans extend beyond the existing trace window
        // OR if no trace end is set yet
        if ($maxSpanEnd && $maxSpanEnd > $traceStart) {
            if (! $existingEnd || $maxSpanEnd > $existingEnd) {
                $trace['ended_at'] = TraceTime::format($maxSpanEnd);
            }
        }

        return $trace;
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

        // If over hard limit, keep only the trace record
        if ($estimatedSize > self::HARD_PAYLOAD_LIMIT) {
            return $this->createErrorPayload($payload, $estimatedSize);
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
            'truncated_at' => TraceTime::format(TraceTime::now()),
        ];

        return $payload;
    }

    /**
     * Over the hard limit: keep the trace record (with its real status) and drop the rest.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createErrorPayload(array $payload, int $originalSize): array
    {
        $trace = $payload['trace'] ?? [];
        unset($trace['initial_state'], $trace['final_state']);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'trace' => $trace,
            'spans' => [],
            'llm_calls' => [],
            '_meta' => [
                'truncated' => true,
                'truncation_applied' => ['complete_payload'],
                'error_reason' => 'payload_too_large',
                'original_size_bytes' => $originalSize,
                'dropped' => [
                    'spans' => count($payload['spans'] ?? []),
                    'llm_calls' => count($payload['llm_calls'] ?? []),
                ],
                'truncated_at' => TraceTime::format(TraceTime::now()),
            ],
        ];
    }
}
