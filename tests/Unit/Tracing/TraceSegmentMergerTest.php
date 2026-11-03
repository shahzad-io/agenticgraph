<?php

declare(strict_types=1);

use Shahzad\AgenticGraph\Tracing\TraceSegmentMerger;

/**
 * @param  array<string, mixed>  $segment
 * @param  array<string, mixed>  $trace
 * @param  array<int, array<string, mixed>>  $spans
 * @return array<string, mixed>
 */
function tsmEnvelope(array $segment, array $trace = [], array $spans = [], array $llmCalls = []): array
{
    return [
        'schema_version' => 'agenticgraph.trace.v1.1',
        'trace' => $trace + ['id' => 'tr_m', 'graph_name' => 'g', 'status' => 'running'],
        'segment' => $segment + ['kind' => 'continued', 'final' => false],
        'spans' => $spans,
        'llm_calls' => $llmCalls,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function tsmInterruptThenResume(): array
{
    $root = ['id' => 'span_root', 'trace_id' => 'tr_m', 'parent_id' => null, 'span_type' => 'graph'];

    return [
        tsmEnvelope(
            ['id' => 'seg_1', 'kind' => 'initial', 'started_at' => '2026-09-27T10:00:00.000000+00:00', 'ended_at' => '2026-09-27T10:00:02.000000+00:00'],
            ['status' => 'interrupted', 'started_at' => '2026-09-27T10:00:00.000000+00:00', 'interrupt_reason' => 'approval', 'interrupted_at' => '2026-09-27T10:00:02.000000+00:00', 'interrupt_expires_at' => '2026-10-04T10:00:02.000000+00:00'],
            [
                $root + ['status' => 'interrupted', 'started_at' => '2026-09-27T10:00:00.000000+00:00', 'ended_at' => '2026-09-27T10:00:02.000000+00:00', 'duration_ms' => 2000.0],
                ['id' => 'span_a', 'trace_id' => 'tr_m', 'parent_id' => 'span_root', 'span_type' => 'node'],
            ],
            [['id' => 'llm_1', 'trace_id' => 'tr_m']],
        ),
        tsmEnvelope(
            ['id' => 'seg_2', 'kind' => 'resumed', 'final' => true, 'started_at' => '2026-09-27T10:10:00.000000+00:00', 'ended_at' => '2026-09-27T10:10:03.000000+00:00'],
            ['status' => 'completed', 'resumed_at' => '2026-09-27T10:10:00.000000+00:00', 'ended_at' => '2026-09-27T10:10:03.000000+00:00'],
            [
                $root + ['status' => 'success', 'started_at' => '2026-09-27T10:10:00.000000+00:00', 'ended_at' => '2026-09-27T10:10:03.000000+00:00', 'duration_ms' => 3000.0],
                ['id' => 'span_b', 'trace_id' => 'tr_m', 'parent_id' => 'span_root', 'span_type' => 'node'],
            ],
            [['id' => 'llm_2', 'trace_id' => 'tr_m']],
        ),
    ];
}

it('merges a root interrupted in segment 1 and completed in the final segment as completed (guard)', function () {
    $merged = (new TraceSegmentMerger)->merge(array_reverse(tsmInterruptThenResume()));
    $root = array_values(array_filter($merged['spans'], fn (array $span): bool => $span['parent_id'] === null));

    expect($merged['trace']['status'])->toBe('completed')
        ->and($merged['trace'])->not->toHaveKey('interrupt_reason')
        ->and($merged['trace'])->not->toHaveKey('interrupted_at')
        ->and($merged['trace'])->not->toHaveKey('interrupt_expires_at')
        ->and($merged['trace']['started_at'])->toBe('2026-09-27T10:00:00.000000+00:00')
        ->and($merged['trace']['ended_at'])->toBe('2026-09-27T10:10:03.000000+00:00')
        ->and($merged['trace']['duration_ms'])->toBe(603000.0)
        // Human wait excluded: 2 s before the interrupt plus 3 s after the resume.
        ->and($merged['trace']['active_ms'])->toBe(5000.0)
        ->and(array_column($merged['segments'], 'id'))->toBe(['seg_1', 'seg_2'])
        ->and(array_column($merged['spans'], 'id'))->toBe(['span_root', 'span_a', 'span_b'])
        ->and(array_column($merged['llm_calls'], 'id'))->toBe(['llm_1', 'llm_2'])
        ->and($root)->toHaveCount(1)
        ->and($root[0]['status'])->toBe('success')
        // Widened back to the trace start; the end stays the final segment's end.
        ->and($root[0]['started_at'])->toBe('2026-09-27T10:00:00.000000+00:00')
        ->and($root[0]['ended_at'])->toBe($merged['trace']['ended_at'])
        ->and($root[0]['duration_ms'])->toBe(603000.0);
});

it('gives the same result when a segment arrives twice (guard)', function () {
    $segments = tsmInterruptThenResume();
    $merger = new TraceSegmentMerger;

    expect($merger->merge([$segments[0], ...$segments, $segments[1]]))->toBe($merger->merge($segments));
});

it('counts overlapping branch segments once: active_ms is their union, not their sum', function () {
    $merged = (new TraceSegmentMerger)->merge([
        tsmEnvelope(['id' => 'seg_init', 'kind' => 'initial', 'started_at' => '2026-09-27T10:00:00.000000+00:00', 'ended_at' => '2026-09-27T10:00:01.000000+00:00'], ['started_at' => '2026-09-27T10:00:00.000000+00:00']),
        // Two parallel branches: 10:00:02 to 10:00:06 and 10:00:04 to 10:00:08.500
        tsmEnvelope(['id' => 'seg_a', 'started_at' => '2026-09-27T10:00:02.000000+00:00', 'ended_at' => '2026-09-27T10:00:06.000000+00:00']),
        tsmEnvelope(['id' => 'seg_b', 'started_at' => '2026-09-27T10:00:04.000000+00:00', 'ended_at' => '2026-09-27T10:00:08.500000+00:00']),
        tsmEnvelope(['id' => 'seg_end', 'final' => true, 'started_at' => '2026-09-27T10:00:09.000000+00:00', 'ended_at' => '2026-09-27T10:00:10.000000+00:00'], ['status' => 'completed']),
    ]);

    expect($merged['trace']['active_ms'])->toBe(1000.0 + 6500.0 + 1000.0)
        ->and($merged['trace']['duration_ms'])->toBe(10000.0)
        ->and($merged['trace']['status'])->toBe('completed');
});

it('covers a queued segment that ends after the final segment: duration to the last end, active_ms within it, the root keeps the graph end', function () {
    $root = ['id' => 'span_root', 'trace_id' => 'tr_m', 'parent_id' => null, 'span_type' => 'graph', 'status' => 'success',
        'started_at' => '2026-09-27T10:00:00.000000+00:00', 'ended_at' => '2026-09-27T10:00:01.000000+00:00', 'duration_ms' => 1000.0];
    $merged = (new TraceSegmentMerger)->merge([
        tsmEnvelope(['id' => 'seg_init', 'kind' => 'initial', 'final' => true, 'started_at' => '2026-09-27T10:00:00.000000+00:00', 'ended_at' => '2026-09-27T10:00:01.000000+00:00'],
            ['status' => 'completed', 'started_at' => '2026-09-27T10:00:00.000000+00:00', 'ended_at' => '2026-09-27T10:00:01.000000+00:00'], [$root]),
        tsmEnvelope(['id' => 'seg_late', 'started_at' => '2026-09-27T10:00:02.000000+00:00', 'ended_at' => '2026-09-27T10:00:04.000000+00:00'], [],
            [['id' => 'span_batch', 'trace_id' => 'tr_m', 'parent_id' => 'span_root', 'span_type' => 'vector', 'started_at' => '2026-09-27T10:00:02.000000+00:00', 'ended_at' => '2026-09-27T10:00:04.000000+00:00']]),
    ]);
    $mergedRoot = array_values(array_filter($merged['spans'], fn (array $span): bool => $span['parent_id'] === null))[0];

    expect($merged['trace']['status'])->toBe('completed')
        ->and($merged['trace']['ended_at'])->toBe('2026-09-27T10:00:04.000000+00:00')
        ->and($merged['trace']['duration_ms'])->toBe(4000.0)
        ->and($merged['trace']['active_ms'])->toBe(3000.0)
        ->and($mergedRoot['ended_at'])->toBe('2026-09-27T10:00:01.000000+00:00')
        ->and($mergedRoot['duration_ms'])->toBe(1000.0);
});

it('without a final segment takes status from the latest initial or resumed segment and leaves out ended_at and duration_ms', function () {
    $merged = (new TraceSegmentMerger)->merge([
        tsmEnvelope(['id' => 'seg_1', 'kind' => 'initial', 'started_at' => '2026-09-27T10:00:00.000000+00:00', 'ended_at' => '2026-09-27T10:00:05.000000+00:00'], ['status' => 'interrupted', 'interrupt_reason' => 'approval']),
        tsmEnvelope(['id' => 'seg_2', 'started_at' => '2026-09-27T10:00:01.000000+00:00', 'ended_at' => '2026-09-27T10:00:02.000000+00:00'], ['status' => 'running']),
    ]);

    expect($merged['trace']['status'])->toBe('interrupted')
        ->and($merged['trace']['interrupt_reason'])->toBe('approval')
        ->and($merged['trace'])->not->toHaveKeys(['ended_at', 'duration_ms'])
        ->and($merged['trace']['active_ms'])->toBe(5000.0);
});

it('a continued segment that ends after an interrupt does not set the status (D8)', function () {
    $merged = (new TraceSegmentMerger)->merge([
        tsmEnvelope(['id' => 'seg_1', 'kind' => 'initial', 'started_at' => '2026-09-27T10:00:00.000000+00:00', 'ended_at' => '2026-09-27T10:00:02.000000+00:00'], ['status' => 'interrupted', 'interrupt_reason' => 'approval']),
        tsmEnvelope(['id' => 'seg_2', 'started_at' => '2026-09-27T10:00:01.000000+00:00', 'ended_at' => '2026-09-27T10:00:09.000000+00:00'], ['status' => 'running']),
    ]);

    expect($merged['trace']['status'])->toBe('interrupted')
        ->and($merged['trace']['interrupt_reason'])->toBe('approval');
});

it('keeps each segment _meta in its segments entry, error_reason, and dropped summed over segments', function () {
    $tooLarge = ['truncated' => true, 'truncation_applied' => ['complete_payload'], 'error_reason' => 'payload_too_large', 'original_size_bytes' => 12_000_000, 'dropped' => ['spans' => 40, 'llm_calls' => 7], 'truncated_at' => '2026-09-27T10:00:05.000000+00:00'];
    $soft = ['truncated' => true, 'truncation_applied' => ['responses', 'prompts', 'spans'], 'original_size_bytes' => 2_000_000, 'dropped' => ['spans' => 12, 'llm_calls' => 0], 'truncated_at' => '2026-09-27T10:00:09.000000+00:00'];
    $merged = (new TraceSegmentMerger)->merge([
        tsmEnvelope(['id' => 'seg_1', 'kind' => 'initial', 'started_at' => '2026-09-27T10:00:00.000000+00:00', 'ended_at' => '2026-09-27T10:00:05.000000+00:00']) + ['_meta' => $tooLarge],
        tsmEnvelope(['id' => 'seg_2', 'started_at' => '2026-09-27T10:00:06.000000+00:00', 'ended_at' => '2026-09-27T10:00:07.000000+00:00']),
        tsmEnvelope(['id' => 'seg_3', 'final' => true, 'started_at' => '2026-09-27T10:00:08.000000+00:00', 'ended_at' => '2026-09-27T10:00:09.000000+00:00'], ['status' => 'completed']) + ['_meta' => $soft],
    ]);

    expect($merged['_meta'])->toBe([
        'truncated' => true,
        'truncation_applied' => ['complete_payload', 'responses', 'prompts', 'spans'],
        'error_reason' => 'payload_too_large',
        'dropped' => ['spans' => 52, 'llm_calls' => 7],
    ])
        ->and($merged['segments'][0]['_meta'])->toBe($tooLarge)
        ->and($merged['segments'][1])->not->toHaveKey('_meta')
        ->and($merged['segments'][2]['_meta'])->toBe($soft);
});

it('keeps the interrupt on the segment that ended interrupted, with its reason and time', function () {
    $merged = (new TraceSegmentMerger)->merge(tsmInterruptThenResume());

    expect($merged['trace'])->not->toHaveKey('interrupt_reason')
        ->and($merged['segments'][0]['interrupt'])->toBe(['reason' => 'approval', 'at' => '2026-09-27T10:00:02.000000+00:00'])
        ->and($merged['segments'][1])->not->toHaveKey('interrupt')
        ->and(array_keys($merged['segments'][0]))->toContain('interrupt');
});

it('keeps the interrupt marker on a still-interrupted trace too', function () {
    $merged = (new TraceSegmentMerger)->merge([tsmInterruptThenResume()[0]]);

    expect($merged['trace']['status'])->toBe('interrupted')
        ->and($merged['trace']['interrupt_expires_at'])->toBe('2026-10-04T10:00:02.000000+00:00')
        ->and($merged['segments'][0]['interrupt']['reason'])->toBe('approval');
});
