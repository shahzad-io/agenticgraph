<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Shahzad\AgenticGraph\Tracing\Exporters\FileExporter;

beforeEach(function () {
    config(['agenticgraph.tracing.enabled' => true]);
    $this->traceDir = sys_get_temp_dir().'/ag-segments-'.bin2hex(random_bytes(4));
});

afterEach(function () {
    foreach (glob($this->traceDir.'/*/*.json') ?: [] as $file) {
        unlink($file);
    }
    foreach (glob($this->traceDir.'/*') ?: [] as $dir) {
        rmdir($dir);
    }
    @rmdir($this->traceDir);
});

/**
 * @return list<array<string, mixed>>
 */
function tsgWritten(string $dir, string $traceId): array
{
    $files = glob($dir.'/'.$traceId.'/*.json') ?: [];
    sort($files);

    return array_map(fn (string $file): array => json_decode((string) file_get_contents($file), true), $files);
}

it('sends one trace without touching another open trace in the same exporter', function () {
    $exporter = new FileExporter($this->traceDir);
    $exporter->startTrace('tr_one', 'g');
    $exporter->startTrace('tr_two', 'g');
    $exporter->addSpan(['id' => 'span_one', 'trace_id' => 'tr_one']);
    $exporter->addSpan(['id' => 'span_two', 'trace_id' => 'tr_two']);

    $exporter->completeTrace('tr_one', [], 5.0);

    expect(array_keys($exporter->getBuffer()['traces']))->toBe(['tr_two'])
        ->and(array_column($exporter->getBuffer()['spans'], 'id'))->toBe(['span_two']);

    $exporter->addSpan(['id' => 'span_two_b', 'trace_id' => 'tr_two']);
    $exporter->completeTrace('tr_two', [], 5.0);

    expect(array_column(tsgWritten($this->traceDir, 'tr_one')[0]['spans'], 'id'))->toBe(['span_one'])
        ->and(array_column(tsgWritten($this->traceDir, 'tr_two')[0]['spans'], 'id'))->toBe(['span_two', 'span_two_b'])
        ->and(tsgWritten($this->traceDir, 'tr_two')[0]['segment']['kind'])->toBe('initial');
});

it('makes the same sampling decision for a trace id in every exporter instance', function () {
    config(['agenticgraph.tracing.sampling.rate' => 0.5]);
    $ids = array_map(fn (int $i): string => "tr_sample_{$i}", range(1, 40));

    $decisions = function () use ($ids): array {
        $exporter = new FileExporter($this->traceDir);
        $method = new ReflectionMethod($exporter, 'isSampled');

        return array_map(fn (string $id): bool => $method->invoke($exporter, $id), $ids);
    };

    $first = $decisions();

    // Two instances stand in for two queue workers: a random draw would disagree on some ids.
    expect($decisions())->toBe($first)
        ->and(array_filter($first))->not->toBeEmpty()
        ->and(array_filter($first, fn (bool $sampled): bool => ! $sampled))->not->toBeEmpty();
});

it('writes immediately in the console even when a request is bound', function () {
    app()->instance('request', Request::create('/'));

    $exporter = new FileExporter($this->traceDir);
    $exporter->startTrace('tr_console', 'g');
    $exporter->completeTrace('tr_console', [], 5.0);

    expect(tsgWritten($this->traceDir, 'tr_console'))->toHaveCount(1);
});

it('keeps every trace id inside the trace directory, including dot and separator ids', function (string $traceId) {
    $exporter = new FileExporter($this->traceDir);
    $exporter->startTrace($traceId, 'g');
    $exporter->completeTrace($traceId, [], 1.0);

    $written = glob($this->traceDir.'/*/*.json') ?: [];
    expect($written)->toHaveCount(1)
        ->and(dirname($written[0], 2))->toBe($this->traceDir)
        ->and(basename(dirname($written[0])))->not->toBeIn(['.', '..', ''])
        ->and(glob(dirname($this->traceDir).'/*.json') ?: [])->toBe([]);
})->with(['..', '.', '../..', '../escape', '..\\escape', '/abs/path', 'a/../../b', "nul\0byte", '....']);
