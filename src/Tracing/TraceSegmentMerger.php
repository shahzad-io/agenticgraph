<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

/**
 * Merges the segment envelopes of one trace into a single trace (the merged shape is in spec/tracing-wire-v1.md).
 * Reference implementation of the server-side merge: the final segment wins, otherwise the
 * segment that ended later wins. active_ms is the union of the segment windows.
 */
final class TraceSegmentMerger
{
    /**
     * @param  array<int, array<string, mixed>>  $envelopes
     * @return array<string, mixed>
     */
    public function merge(array $envelopes): array
    {
        $segments = [];
        foreach ($envelopes as $envelope) {
            $segment = is_array($envelope['segment'] ?? null) ? $envelope['segment'] : ['id' => '', 'final' => true];
            $segments[(string) ($segment['id'] ?? '')] = $envelope + ['segment' => $segment];
        }

        usort($segments, fn (array $a, array $b): int => [(string) ($a['segment']['started_at'] ?? ''), (string) ($a['segment']['id'] ?? '')]
            <=> [(string) ($b['segment']['started_at'] ?? ''), (string) ($b['segment']['id'] ?? '')]);

        // Precedence order: later-ended segments last, the final segment last of all.
        $ranked = $segments;
        usort($ranked, fn (array $a, array $b): int => $this->rank($a['segment']) <=> $this->rank($b['segment']));

        $trace = [];
        $spans = [];
        $llmCalls = [];
        $truncation = [];
        foreach ($ranked as $envelope) {
            $trace = array_merge($trace, is_array($envelope['trace'] ?? null) ? $envelope['trace'] : []);
            foreach ($envelope['spans'] ?? [] as $span) {
                $spans[(string) ($span['id'] ?? count($spans))] = $span;
            }
            foreach ($envelope['llm_calls'] ?? [] as $call) {
                $llmCalls[(string) ($call['id'] ?? count($llmCalls))] = $call;
            }
            if (is_array($envelope['_meta'] ?? null)) {
                $truncation = [...$truncation, ...($envelope['_meta']['truncation_applied'] ?? [])];
            }
        }

        $final = null;
        foreach ($segments as $envelope) {
            if ($envelope['segment']['final'] ?? false) {
                $final = $envelope['segment'];
            }
        }

        $initialStart = null;
        foreach ($segments as $envelope) {
            if (($envelope['segment']['kind'] ?? null) === 'initial') {
                $initialStart = TraceTime::parse($envelope['trace']['started_at'] ?? null);
            }
        }

        $windows = [];
        foreach ($segments as $envelope) {
            $start = TraceTime::parse($envelope['segment']['started_at'] ?? null);
            $end = TraceTime::parse($envelope['segment']['ended_at'] ?? null);
            if ($start && $end) {
                $windows[] = [TraceTime::toMicrotime($start), TraceTime::toMicrotime($end)];
            }
        }

        $startedAt = $initialStart ?? ($windows === [] ? null : TraceTime::fromMicrotime(min(array_column($windows, 0))));
        $endedAt = TraceTime::parse($final['ended_at'] ?? null)
            ?? ($windows === [] ? null : TraceTime::fromMicrotime(max(array_column($windows, 1))));

        if ($startedAt) {
            $trace['started_at'] = TraceTime::format($startedAt);
        }
        if ($endedAt) {
            $trace['ended_at'] = TraceTime::format($endedAt);
        }
        if ($startedAt && $endedAt) {
            $trace['duration_ms'] = TraceTime::diffMs($startedAt, $endedAt);
            $trace['active_ms'] = $this->unionMs($windows);
        }
        if (($trace['status'] ?? null) !== 'interrupted') {
            unset($trace['interrupt_reason'], $trace['interrupted_at']);
        }

        $spans = array_values($spans);
        if ($startedAt) {
            $spans = array_map(fn (array $span): array => $this->widenRoot($span, $startedAt), $spans);
        }

        $merged = [
            'schema_version' => TracePayloadBuilder::SCHEMA_VERSION,
            'trace' => $trace,
            'segments' => array_map(fn (array $envelope): array => $envelope['segment'], $segments),
            'spans' => $spans,
            'llm_calls' => array_values($llmCalls),
        ];

        if ($truncation !== []) {
            $merged['_meta'] = ['truncated' => true, 'truncation_applied' => array_values(array_unique($truncation))];
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return array{int, string}
     */
    private function rank(array $segment): array
    {
        return [($segment['final'] ?? false) ? 1 : 0, (string) ($segment['ended_at'] ?? '')];
    }

    /**
     * Total covered time of possibly overlapping windows (parallel branch segments overlap).
     *
     * @param  list<array{float, float}>  $windows
     */
    private function unionMs(array $windows): float
    {
        usort($windows, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $totalMicros = 0;
        $current = null;
        foreach ($windows as [$start, $end]) {
            $start = (int) round($start * 1_000_000);
            $end = (int) round($end * 1_000_000);
            if ($current === null || $start > $current[1]) {
                if ($current !== null) {
                    $totalMicros += $current[1] - $current[0];
                }
                $current = [$start, $end];
            } else {
                $current[1] = max($current[1], $end);
            }
        }
        if ($current !== null) {
            $totalMicros += $current[1] - $current[0];
        }

        return $totalMicros / 1000;
    }

    /**
     * The root span of a resumed trace comes from the final segment, which only knows its own
     * start. Widen it back to the trace start.
     *
     * @param  array<string, mixed>  $span
     * @return array<string, mixed>
     */
    private function widenRoot(array $span, \DateTimeImmutable $startedAt): array
    {
        if (($span['parent_id'] ?? null) !== null || ($span['span_type'] ?? null) !== 'graph') {
            return $span;
        }

        $spanStart = TraceTime::parse($span['started_at'] ?? null);
        $spanEnd = TraceTime::parse($span['ended_at'] ?? null);
        if ($spanStart && $spanStart > $startedAt) {
            $span['started_at'] = TraceTime::format($startedAt);
            if ($spanEnd) {
                $span['duration_ms'] = TraceTime::diffMs($startedAt, $spanEnd);
            }
        }

        return $span;
    }
}
