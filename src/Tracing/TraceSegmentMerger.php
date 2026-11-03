<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

/**
 * Merges the segment envelopes of one trace into a single trace (the Pulse exporter's internal, versioned format).
 * Reference implementation of the server-side merge: the final segment sets the status, otherwise the
 * latest initial or resumed segment does (a continued segment never does). active_ms is the union of the segment windows.
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
            $id = (string) ($segment['id'] ?? '');
            // R2: the first copy of a segment id stays. R1: an envelope without a segment block (id '') is replaced by a later one.
            if ($id === '' || ! isset($segments[$id])) {
                $segments[$id] = $envelope + ['segment' => $segment];
            }
        }

        // R3: parsed start, then id.
        usort($segments, fn (array $a, array $b): int => [$this->time($a['segment']['started_at'] ?? null), (string) ($a['segment']['id'] ?? '')]
            <=> [$this->time($b['segment']['started_at'] ?? null), (string) ($b['segment']['id'] ?? '')]);

        // Precedence order: later-ended segments last, the final segment last of all.
        $ranked = $segments;
        usort($ranked, fn (array $a, array $b): int => $this->rank($a['segment']) <=> $this->rank($b['segment']));

        $trace = [];
        $spans = [];
        $llmCalls = [];
        $meta = [];
        $status = null;
        $last = null;
        foreach ($ranked as $envelope) {
            $last = $envelope['trace']['status'] ?? $last;
            $trace = array_merge($trace, array_diff_key(is_array($envelope['trace'] ?? null) ? $envelope['trace'] : [], ['status' => 1]));
            $status = $this->statusFrom($envelope, $status);
            foreach ($envelope['spans'] ?? [] as $span) {
                $spans[(string) ($span['id'] ?? count($spans))] = $span;
            }
            foreach ($envelope['llm_calls'] ?? [] as $call) {
                $llmCalls[(string) ($call['id'] ?? count($llmCalls))] = $call;
            }
            if (is_array($envelope['_meta'] ?? null)) {
                $meta = $this->mergeMeta($meta, $envelope['_meta']);
            }
        }

        // R4: a trace with only continued segments takes the status of the last of them.
        $status ??= $last;
        if ($status !== null) {
            $trace['status'] = $status;
        }

        // R1: a lone envelope without a segment block keeps its own times.
        $passThrough = count($segments) === 1 && ($segments[0]['segment']['id'] ?? '') === '' && ! isset($segments[0]['segment']['started_at']);

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

        // R6: the initial segment's trace start, else the earliest segment start of all segments, also those without an end.
        $starts = array_filter(array_map(fn (array $envelope): ?float => ($t = TraceTime::parse($envelope['segment']['started_at'] ?? null)) ? TraceTime::toMicrotime($t) : null, $segments));
        $startedAt = $initialStart ?? ($starts === [] ? null : TraceTime::fromMicrotime(min($starts)));
        // The last segment to end, which is the final one unless queued work outlived the run.
        $finalEnd = TraceTime::parse($final['ended_at'] ?? null);
        $lastEnd = $windows === [] ? null : TraceTime::fromMicrotime(max(array_column($windows, 1)));
        $endedAt = $finalEnd !== null && $lastEnd !== null ? max($finalEnd, $lastEnd) : ($finalEnd ?? $lastEnd);

        // A trace that has not finished (running or waiting at an interrupt) has no end yet; active_ms is the time so far.
        $finished = in_array($trace['status'] ?? null, ['completed', 'failed'], true);
        if ($passThrough) {
            $startedAt = null;
        }
        if ($startedAt) {
            $trace['started_at'] = TraceTime::format($startedAt);
        }
        if ($passThrough) {
            // nothing recomputed
        } elseif (! $finished) {
            unset($trace['ended_at'], $trace['duration_ms']);
        } elseif ($endedAt) {
            $trace['ended_at'] = TraceTime::format($endedAt);
            if ($startedAt) {
                $trace['duration_ms'] = TraceTime::diffMs($startedAt, $endedAt);
            }
        }
        if ($windows !== [] && ! $passThrough) {
            $trace['active_ms'] = $this->unionMs($windows);
        }
        if (($trace['status'] ?? null) !== 'interrupted') {
            unset($trace['interrupt_reason'], $trace['interrupted_at'], $trace['interrupt_expires_at']);
        }

        $spans = array_values($spans);
        if ($startedAt) {
            $spans = array_map(fn (array $span): array => $this->widenRoot($span, $startedAt), $spans);
        }

        $merged = [
            'schema_version' => TracePayloadBuilder::SCHEMA_VERSION,
            'trace' => $trace,
            'segments' => array_map(fn (array $envelope): array => $envelope['segment']
                + $this->interruptMarker($envelope)
                + (is_array($envelope['_meta'] ?? null) ? ['_meta' => $envelope['_meta']] : []), $segments),
            'spans' => $spans,
            'llm_calls' => array_values($llmCalls),
        ];

        if ($meta !== []) {
            $merged['_meta'] = $meta;
        }

        return $merged;
    }

    /**
     * Trace status (D8): the final segment's; without one, the latest initial or resumed segment's.
     * A continued segment never sets it. Called in rank order, so the last qualifying envelope wins.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function statusFrom(array $envelope, mixed $current): mixed
    {
        $segment = $envelope['segment'];
        $status = $envelope['trace']['status'] ?? null;
        if ($status === null || ! (($segment['final'] ?? false) || in_array($segment['kind'] ?? null, ['initial', 'resumed'], true))) {
            return $current;
        }

        return $status;
    }

    /**
     * Merged _meta: the union of truncation_applied, the first error_reason, dropped summed.
     *
     * @param  array<string, mixed>  $merged
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function mergeMeta(array $merged, array $meta): array
    {
        $applied = array_values(array_unique([...($merged['truncation_applied'] ?? []), ...($meta['truncation_applied'] ?? [])]));
        $result = ['truncated' => true, 'truncation_applied' => $applied];

        $errorReason = $merged['error_reason'] ?? $meta['error_reason'] ?? null;
        if ($errorReason !== null) {
            $result['error_reason'] = $errorReason;
        }
        if (isset($merged['dropped']) || isset($meta['dropped'])) {
            $result['dropped'] = [
                'spans' => (int) ($merged['dropped']['spans'] ?? 0) + (int) ($meta['dropped']['spans'] ?? 0),
                'llm_calls' => (int) ($merged['dropped']['llm_calls'] ?? 0) + (int) ($meta['dropped']['llm_calls'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * A segment that ended as interrupted keeps that in the merged trace, where the trace-level interrupt keys are
     * removed once the run goes on: the reason and the time it was interrupted.
     *
     * @param  array<string, mixed>  $envelope
     * @return array{interrupt?: array{reason: mixed, at: mixed}}
     */
    private function interruptMarker(array $envelope): array
    {
        $trace = is_array($envelope['trace'] ?? null) ? $envelope['trace'] : [];
        if (($trace['status'] ?? null) !== 'interrupted') {
            return [];
        }

        return ['interrupt' => ['reason' => $trace['interrupt_reason'] ?? null, 'at' => $trace['interrupted_at'] ?? $envelope['segment']['ended_at'] ?? null]];
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return array{int, float}
     */
    private function rank(array $segment): array
    {
        return [($segment['final'] ?? false) ? 1 : 0, $this->time($segment['ended_at'] ?? null)];
    }

    /** Parsed time as microtime; a missing or unreadable time sorts before every real one. */
    private function time(mixed $value): float
    {
        $at = TraceTime::parse($value);

        return $at ? TraceTime::toMicrotime($at) : -INF;
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
     * The root graph span of a resumed trace comes from the final segment, which only knows its own
     * start. Any such span (parent_id null, span_type graph) that starts after the trace is widened back to the trace start (R9). Its end stays the graph's end, also when queued
     * work ended later (those spans carry links and may end after the root).
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
