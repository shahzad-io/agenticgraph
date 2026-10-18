<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\NullExporter;

class RuntimeTraceProbeNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('probed', true);
    }
}

// TestCase boots with tracing off and the auto exporter (null under unit tests).
// These tests set the flag and the driver at runtime, then run a real graph.
beforeEach(function () {
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    $this->traceDir = sys_get_temp_dir().'/ag-runtime-trace-'.bin2hex(random_bytes(4));

    $graph = GraphBuilder::create('runtime-trace-probe')
        ->addNode('probe', RuntimeTraceProbeNode::class)
        ->setEntryPoint('probe')
        ->addExitPoint('probe')
        ->build();
    AgenticGraph::register('runtime-trace-probe', $graph);
});

afterEach(function () {
    File::deleteDirectory($this->traceDir);
});

/**
 * @return list<array<string, mixed>>
 */
function runtimeTraceEnvelopes(string $dir, string $executionId): array
{
    $files = glob($dir.'/'.$executionId.'/*.json') ?: [];

    return array_map(fn (string $file): array => json_decode((string) file_get_contents($file), true), $files);
}

it('writes spans when the flag and the file driver are set at runtime and the exporter is rebound', function () {
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'file',
        'agenticgraph.tracing.file.path' => $this->traceDir,
    ]);
    app()->forgetInstance(TracerExporter::class);

    $handle = AgenticGraph::run('runtime-trace-probe', new State);

    $envelopes = runtimeTraceEnvelopes($this->traceDir, $handle->id());
    expect($envelopes)->toHaveCount(1)
        ->and($envelopes[0]['schema_version'])->toBe('agenticgraph.trace.v1.1')
        ->and($envelopes[0]['segment']['final'])->toBeTrue()
        ->and(array_column($envelopes[0]['spans'], 'span_type'))->toContain('graph', 'node');
});

it('writes nothing when only the flag changes at runtime under the auto exporter', function () {
    config(['agenticgraph.tracing.enabled' => true]);

    $handle = AgenticGraph::run('runtime-trace-probe', new State);

    expect(runtimeTraceEnvelopes($this->traceDir, $handle->id()))->toBe([]);
});

it('keeps the exporter it already resolved when the driver changes later without a rebind', function () {
    // Resolve the singleton first, as any earlier run in the process would.
    expect(app(TracerExporter::class))->toBeInstanceOf(NullExporter::class);

    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'file',
        'agenticgraph.tracing.file.path' => $this->traceDir,
    ]);

    $handle = AgenticGraph::run('runtime-trace-probe', new State);

    expect(runtimeTraceEnvelopes($this->traceDir, $handle->id()))->toBe([]);
});
