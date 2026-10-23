<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\OtlpExporter;
use Shahzad\AgenticGraph\Tracing\PulseExporter;

class RuntimeToggleExporter extends PulseExporter
{
    /** @var array<int, array<string, mixed>> */
    public array $spans = [];

    public function addSpan(array $span): void
    {
        $this->spans[] = $span;
    }
}

// TestCase boots the app with tracing off, so these run in the situation C4 fixes.

it('records spans when tracing is turned on after boot', function () {
    Http::fake();
    config(['agenticgraph.tracing.api_key' => 'ap_test']);
    $exporter = new RuntimeToggleExporter;
    app()->instance(TracerExporter::class, $exporter);

    config(['agenticgraph.tracing.enabled' => true]);
    (new GraphExecutor)->execute(createLargeGraph(3), createTestState());

    $nodeSpans = array_filter($exporter->spans, fn (array $span): bool => ($span['span_type'] ?? null) === 'node');
    expect($nodeSpans)->toHaveCount(3);
});

it('reads tracing.enabled per call in the pulse and otlp exporters', function () {
    config(['agenticgraph.tracing.api_key' => 'ap_test', 'agenticgraph.tracing.otlp.endpoint' => 'http://collector.test/v1/traces']);
    $pulse = new PulseExporter;
    $otlp = new OtlpExporter;
    expect($pulse->isEnabled())->toBeFalse()->and($otlp->isEnabled())->toBeFalse();

    config(['agenticgraph.tracing.enabled' => true]);
    expect($pulse->isEnabled())->toBeTrue()->and($otlp->isEnabled())->toBeTrue();

    config(['agenticgraph.tracing.enabled' => false]);
    expect($pulse->isEnabled())->toBeFalse()->and($otlp->isEnabled())->toBeFalse();
});

it('guard: booting with tracing off does not build a tracer exporter', function () {
    expect(app()->resolved(TracerExporter::class))->toBeFalse();
});
