<?php

declare(strict_types=1);

use Shahzad\AgenticGraph\Tracing\TraceSegmentMerger;

/**
 * Wire doc rules R1 to R9, each as a variant of the queued-ingest corpus case: an initial final segment A and a
 * late continued segment B. The package merger over all 49 corpus cases is checked against their merged.json
 * files in the ecosystem repo (see B-25).
 *
 * @return array{a: array<string, mixed>, b: array<string, mixed>}
 */
function tsmCorpus(): array
{
    $dir = __DIR__.'/../../Fixtures/trace-corpus/queued-ingest';
    $files = glob($dir.'/segments/*.json');
    sort($files);

    return ['a' => json_decode((string) file_get_contents($files[0]), true), 'b' => json_decode((string) file_get_contents($files[1]), true)];
}

/** @param array<string, mixed> $envelope */
function tsmWith(array $envelope, array $segment, array $trace = []): array
{
    $envelope['segment'] = $segment + $envelope['segment'];
    $envelope['trace'] = $trace + $envelope['trace'];

    return $envelope;
}

it('the unchanged queued-ingest case merges to its merged.json', function () {
    ['a' => $a, 'b' => $b] = tsmCorpus();
    $expected = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/trace-corpus/queued-ingest/merged.json'), true);

    expect(json_decode(json_encode((new TraceSegmentMerger)->merge([$b, $a])), true))->toEqual($expected);
});

it('R2: a repeated segment id keeps the first copy', function () {
    ['a' => $a, 'b' => $b] = tsmCorpus();
    $copy = tsmWith($a, [], ['graph_name' => 'changed']);

    expect((new TraceSegmentMerger)->merge([$a, $b, $copy])['trace']['graph_name'])->toBe('corpus-queued-ingest');
});

it('R6: the trace start is the earliest start of all segments, also one without an end', function () {
    ['a' => $a, 'b' => $b] = tsmCorpus();
    $a = tsmWith($a, ['kind' => 'continued', 'final' => false, 'ended_at' => null], ['status' => 'running']);
    unset($a['trace']['started_at']);

    expect((new TraceSegmentMerger)->merge([$a, $b])['trace']['started_at'])->toBe($a['segment']['started_at']);
});

it('R1: an envelope without a segment block keeps its own times', function () {
    ['a' => $a] = tsmCorpus();
    unset($a['segment']);
    $a['trace'] = ['status' => 'running', 'ended_at' => '2026-11-01T13:08:31.261397+00:00', 'duration_ms' => 18.26, 'active_ms' => 18.26] + $a['trace'];

    $trace = (new TraceSegmentMerger)->merge([$a])['trace'];

    expect($trace['status'])->toBe('running')
        ->and($trace['ended_at'])->toBe('2026-11-01T13:08:31.261397+00:00')
        ->and($trace['duration_ms'])->toBe(18.26)
        ->and($trace['active_ms'])->toBe(18.26)
        ->and($trace['started_at'])->toBe('2026-11-01T13:08:31.243137+00:00');
});

it('R4: ties and different time formats order by parsed ended_at, a null end first', function () {
    ['a' => $a, 'b' => $b] = tsmCorpus();
    $seg = fn (string $id, ?string $end, string $marker): array => tsmWith($b, ['id' => $id, 'started_at' => '2026-11-01T13:08:33.000000+00:00', 'ended_at' => $end], ['marker' => $marker]);

    $merged = (new TraceSegmentMerger)->merge([
        $seg('seg_c', '2026-11-01T13:08:34.500000+00:00', 'two'),
        $seg('seg_b', '2026-11-01T15:08:34.000000+02:00', 'one'),
        $seg('seg_a', null, 'zero'),
        $a,
    ]);

    expect($merged['trace']['marker'])->toBe('two');
});

it('R3: segments order by parsed start time, then id', function () {
    ['b' => $b] = tsmCorpus();
    $seg = fn (string $id, string $start, string $marker): array => tsmWith($b, ['id' => $id, 'started_at' => $start, 'ended_at' => null], ['marker' => $marker]);

    $merged = (new TraceSegmentMerger)->merge([
        $seg('seg_b', '2026-11-01T13:08:33.500000+00:00', 'b'),
        $seg('seg_a', '2026-11-01T15:08:33.000000+02:00', 'a'),
    ]);

    expect(array_column($merged['segments'], 'id'))->toBe(['seg_a', 'seg_b'])
        ->and($merged['trace']['marker'])->toBe('b');
});

it('R4: a trace with only continued segments takes the status of the last of them', function () {
    ['b' => $b] = tsmCorpus();

    expect((new TraceSegmentMerger)->merge([$b])['trace']['status'])->toBe('running');
});

it('R9: any root graph span that starts after the trace start moves back to it (guard)', function () {
    ['a' => $a] = tsmCorpus();
    $initial = tsmWith($a, ['id' => 'seg_init', 'kind' => 'initial', 'final' => false, 'started_at' => '2026-11-01T13:08:20.000000+00:00', 'ended_at' => '2026-11-01T13:08:21.000000+00:00'], ['status' => 'interrupted', 'started_at' => '2026-11-01T13:08:20.000000+00:00']);
    $initial['spans'] = [];
    $resumed = tsmWith($a, ['kind' => 'resumed']);

    $root = collect((new TraceSegmentMerger)->merge([$initial, $resumed])['spans'])->firstWhere('parent_id', null);

    expect($root['started_at'])->toBe('2026-11-01T13:08:20.000000+00:00');
});
