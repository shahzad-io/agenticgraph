<?php

declare(strict_types=1);

use Shahzad\AgenticGraph\Tracing\TracePayloadBuilder;
use Shahzad\AgenticGraph\Tracing\TraceTime;

/**
 * Characterization guards for payload shaping (merge, timestamp fill, trace window, size limits).
 * These pinned the shaping in PulseExporter::buildPayload before it moved to TracePayloadBuilder
 * and passed against both.
 *
 * @param  array<string, mixed>  $trace
 * @param  array<int, array<string, mixed>>  $spans
 * @param  array<int, array<string, mixed>>  $calls
 * @return array<string, mixed>
 */
function tpbBuild(array $trace, array $spans = [], array $calls = []): array
{
    return (new TracePayloadBuilder)->build($trace, $spans, $calls);
}

/** @return array<string, mixed> */
function tpbTrace(array $overrides = []): array
{
    return array_merge([
        'id' => 'tr_1',
        'graph_name' => 'g',
        'status' => 'completed',
        'started_at' => '2026-09-27T10:00:00.000000+00:00',
        'ended_at' => '2026-09-27T10:00:05.000000+00:00',
        'duration_ms' => 5000.0,
    ], $overrides);
}

/** @return array<string, mixed> */
function tpbSpan(string $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'trace_id' => 'tr_1',
        'started_at' => '2026-09-27T10:00:01.000000+00:00',
        'ended_at' => '2026-09-27T10:00:02.000000+00:00',
        'duration_ms' => 1000.0,
    ], $overrides);
}

it('merges spans and llm calls by trace id and id, last value at first position, id-less items kept', function () {
    $payload = tpbBuild(tpbTrace(), [
        tpbSpan('a', ['status' => 'interrupted']),
        tpbSpan('b'),
        ['trace_id' => 'tr_1', 'note' => 'no id'],
        tpbSpan('a', ['status' => 'completed']),
        tpbSpan('a', ['trace_id' => 'other']),
    ], [
        ['id' => 'c1', 'trace_id' => 'tr_1', 'response' => 'first'],
        ['id' => 'c1', 'trace_id' => 'tr_1', 'response' => 'second'],
    ]);

    expect(array_map(fn (array $s) => ($s['trace_id'] ?? '').':'.($s['id'] ?? '-'), $payload['spans']))
        ->toBe(['tr_1:a', 'tr_1:b', 'other:a', 'tr_1:-'])
        ->and($payload['spans'][0]['status'])->toBe('completed')
        ->and($payload['llm_calls'])->toHaveCount(1)
        ->and($payload['llm_calls'][0]['response'])->toBe('second');
});

it('fills only the missing span timestamp from duration_ms', function () {
    $payload = tpbBuild(tpbTrace(), [
        ['id' => 'start_only', 'trace_id' => 'tr_1', 'started_at' => '2026-09-27T10:00:01.000000+00:00', 'duration_ms' => 1500.25],
        ['id' => 'end_only', 'trace_id' => 'tr_1', 'ended_at' => '2026-09-27T10:00:03.000000+00:00', 'duration_ms' => 500],
        tpbSpan('both', ['duration_ms' => 9999]),
    ]);

    $byId = array_column($payload['spans'], null, 'id');

    expect($byId['start_only']['ended_at'])->toBe('2026-09-27T10:00:02.500250+00:00')
        ->and($byId['end_only']['started_at'])->toBe('2026-09-27T10:00:02.500000+00:00')
        ->and($byId['both']['started_at'])->toBe('2026-09-27T10:00:01.000000+00:00')
        ->and($byId['both']['ended_at'])->toBe('2026-09-27T10:00:02.000000+00:00');
});

it('extends an ended trace to the latest span end without touching duration_ms, and never ends an open one', function () {
    $late = tpbBuild(tpbTrace(), [tpbSpan('late', ['ended_at' => '2026-09-27T10:00:07.250000+00:00'])]);
    $inside = tpbBuild(tpbTrace(), [tpbSpan('inside')]);
    $open = tpbBuild(tpbTrace(['ended_at' => null]), [tpbSpan('inside')]);

    expect($late['trace']['ended_at'])->toBe('2026-09-27T10:00:07.250000+00:00')
        ->and($late['trace']['duration_ms'])->toBe(5000.0)
        ->and($inside['trace']['ended_at'])->toBe('2026-09-27T10:00:05.000000+00:00')
        ->and($open['trace']['ended_at'])->toBeNull();
});

it('leaves a payload under 1 MB unchanged apart from shaping', function () {
    $trace = tpbTrace(['initial_state' => ['q' => 'x'], 'final_state' => ['a' => 'y']]);
    $spans = [tpbSpan('a'), tpbSpan('b')];
    $calls = [['id' => 'c1', 'trace_id' => 'tr_1', 'prompt' => 'p', 'response' => 'r']];

    $payload = tpbBuild($trace, $spans, $calls);

    expect($payload['trace'])->toBe($trace)
        ->and($payload['spans'])->toBe($spans)
        ->and($payload['llm_calls'])->toBe($calls)
        ->and($payload)->not->toHaveKey('_meta');
});

it('truncates llm responses first and records _meta', function () {
    $calls = [
        ['id' => 'big', 'trace_id' => 'tr_1', 'prompt' => 'short prompt', 'response' => str_repeat('word ', 300_000)],
        ['id' => 'small', 'trace_id' => 'tr_1', 'prompt' => 'p', 'response' => 'tiny'],
    ];

    $payload = tpbBuild(tpbTrace(), [], $calls);
    $byId = array_column($payload['llm_calls'], null, 'id');

    expect($payload['_meta']['truncated'])->toBeTrue()
        ->and($payload['_meta']['truncation_applied'])->toBe(['responses'])
        ->and($payload['_meta']['original_size_bytes'])->toBeGreaterThan(1_048_576)
        ->and($payload['_meta']['truncated_at'])->toMatch(TRACE_TIME_PATTERN)
        ->and(strlen($byId['big']['response']))->toBeLessThan(800_000)
        ->and($byId['big']['response'])->toEndWith(' [...truncated]')
        ->and($byId['big']['prompt'])->toBe('short prompt')
        ->and($byId['small']['response'])->toBe('tiny');
});

it('falls through responses, prompts, middle spans, then state values', function () {
    $spans = [];
    for ($i = 0; $i < 150; $i++) {
        $spans[] = tpbSpan("s{$i}", ['blob' => str_repeat('x', 12_000)]);
    }
    $trace = tpbTrace(['initial_state' => ['q' => 'x'], 'final_state' => ['a' => 'y'], 'initial_state_keys' => ['q']]);

    $payload = tpbBuild($trace, $spans);
    $ids = array_column($payload['spans'], 'id');

    expect($payload['_meta']['truncation_applied'])->toBe(['responses', 'prompts', 'spans', 'state_values'])
        ->and($ids)->toHaveCount(100)
        ->and($ids[0])->toBe('s0')
        ->and($ids[49])->toBe('s49')
        ->and($ids[50])->toBe('s100')
        ->and($ids[99])->toBe('s149')
        ->and($payload['trace'])->not->toHaveKey('initial_state')
        ->and($payload['trace'])->not->toHaveKey('final_state')
        ->and($payload['trace']['initial_state_keys'])->toBe(['q']);
});

it('drops spans and llm calls when the payload is over 10 MB', function () {
    $calls = [];
    for ($i = 0; $i < 11; $i++) {
        $calls[] = ['id' => "c{$i}", 'trace_id' => 'tr_1', 'response' => str_repeat('y', 1_000_000)];
    }

    $payload = tpbBuild(tpbTrace(['resource' => ['service.name' => 'svc']]), [tpbSpan('a')], $calls);

    expect($payload['trace']['id'])->toBe('tr_1')
        ->and($payload['trace']['graph_name'])->toBe('g')
        ->and($payload['trace']['started_at'])->toBe('2026-09-27T10:00:00.000000+00:00')
        ->and($payload['trace']['resource'])->toBe(['service.name' => 'svc'])
        ->and($payload['spans'])->toBe([])
        ->and($payload['llm_calls'])->toBe([])
        ->and($payload['_meta']['truncated'])->toBeTrue();
});

it('formats filled timestamps with TraceTime', function () {
    $payload = tpbBuild(tpbTrace(), [['id' => 'x', 'trace_id' => 'tr_1', 'started_at' => TraceTime::format(TraceTime::now())]]);

    expect($payload['spans'][0]['ended_at'])->toMatch(TRACE_TIME_PATTERN);
});

it('puts schema_version first in every envelope', function () {
    $payload = tpbBuild(tpbTrace(), [tpbSpan('a')]);

    expect(array_key_first($payload))->toBe('schema_version')
        ->and($payload['schema_version'])->toBe('agenticgraph.trace.v1')
        ->and(array_keys($payload))->toBe(['schema_version', 'trace', 'spans', 'llm_calls']);
});

it('keeps the real trace status on an oversize payload and reports why in _meta', function () {
    $calls = [];
    for ($i = 0; $i < 11; $i++) {
        $calls[] = ['id' => "c{$i}", 'trace_id' => 'tr_1', 'response' => str_repeat('y', 1_000_000)];
    }

    $payload = tpbBuild(tpbTrace(['status' => 'failed', 'initial_state' => ['q' => 'x']]), [tpbSpan('a'), tpbSpan('b')], $calls);

    expect($payload['schema_version'])->toBe('agenticgraph.trace.v1')
        ->and($payload['trace']['status'])->toBe('failed')
        ->and($payload['trace']['ended_at'])->toBe('2026-09-27T10:00:05.000000+00:00')
        ->and($payload['trace'])->not->toHaveKey('error_reason')
        ->and($payload['trace'])->not->toHaveKey('initial_state')
        ->and($payload['_meta']['error_reason'])->toBe('payload_too_large')
        ->and($payload['_meta']['dropped'])->toBe(['spans' => 2, 'llm_calls' => 11])
        ->and($payload['_meta']['truncation_applied'])->toBe(['complete_payload']);
});
