<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\LogExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\NullExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\OtlpExporter;
use Shahzad\AgenticGraph\Tracing\Listeners\TracingListener;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TracerExporterFactory;

it('resolves log exporter when tracing is enabled without api key', function () {
    app()->detectEnvironment(fn (): string => 'local');
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'auto',
        'agenticgraph.tracing.api_key' => null,
    ]);

    $exporter = TracerExporterFactory::make(app());

    expect($exporter)->toBeInstanceOf(LogExporter::class)
        ->and($exporter->isEnabled())->toBeTrue();
});

it('auto is null in unit tests, an explicit driver still sends', function () {
    Http::fake(['*' => Http::response(['status' => 'captured'], 202)]);
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'auto',
        'agenticgraph.tracing.api_key' => 'pk_test',
        'agenticgraph.tracing.endpoint' => 'https://sink.test',
    ]);

    expect(app()->runningUnitTests())->toBeTrue()
        ->and(TracerExporterFactory::make(app()))->toBeInstanceOf(NullExporter::class);

    config(['agenticgraph.tracing.exporter' => 'pulse']);
    $exporter = TracerExporterFactory::make(app());
    $exporter->startTrace('exec_j1', 'j1-graph');
    $exporter->completeTrace('exec_j1');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sink.test/v1/ingest');

    app()->detectEnvironment(fn (): string => 'production');
    config(['agenticgraph.tracing.exporter' => 'auto']);

    expect(TracerExporterFactory::make(app()))->toBeInstanceOf(PulseExporter::class);
});

it('resolves pulse exporter when exporter is pulse and api key is set', function () {
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'pulse',
        'agenticgraph.tracing.api_key' => 'pk_test',
    ]);

    $exporter = TracerExporterFactory::make(app());

    expect($exporter)->toBeInstanceOf(PulseExporter::class)
        ->and($exporter->isEnabled())->toBeTrue();
});

it('resolves null exporter when configured', function () {
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'null',
    ]);

    $exporter = TracerExporterFactory::make(app());

    expect($exporter)->toBeInstanceOf(NullExporter::class)
        ->and($exporter->isEnabled())->toBeTrue();
});

it('resolves null exporter when the env value null reaches config as PHP null', function () {
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => null,
    ]);

    expect(TracerExporterFactory::make(app()))->toBeInstanceOf(NullExporter::class);
});

it('resolves otlp exporter when configured with endpoint', function () {
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'otlp',
        'agenticgraph.tracing.otlp.endpoint' => 'http://localhost:4318/v1/traces',
    ]);

    $exporter = TracerExporterFactory::make(app());

    expect($exporter)->toBeInstanceOf(OtlpExporter::class)
        ->and($exporter->isEnabled())->toBeTrue();
});

it('log exporter receives trace lifecycle calls without agenticpulse', function () {
    Log::spy();

    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'log',
    ]);
    // Console context: the log write happens during flush, not after the response.
    app()->forgetInstance('request');

    $exporter = new LogExporter;
    $listener = new TracingListener($exporter);

    $executionId = 'exec_log_test';
    $startedAt = new DateTimeImmutable('2026-03-01T10:00:00+00:00');

    $listener->onGraphStarted(new GraphExecutionStarted(
        executionId: $executionId,
        graphName: 'demo-graph',
        initialState: new State(['input' => 'x']),
        startedAt: $startedAt,
        spanId: 'span_root',
    ));

    $listener->onGraphCompleted(new GraphExecutionCompleted(
        executionId: $executionId,
        graphName: 'demo-graph',
        finalState: new State(['output' => 'y']),
        completedAt: $startedAt->modify('+2 seconds'),
        duration: 2.0,
        statistics: [],
        spanId: 'span_root',
    ));

    Log::shouldHaveReceived('info')
        ->once()
        ->with('agenticgraph.trace', Mockery::on(function (array $payload) use ($executionId): bool {
            return ($payload['trace']['id'] ?? null) === $executionId
                && ($payload['trace']['status'] ?? null) === 'completed'
                && isset($payload['spans'], $payload['llm_calls']);
        }));
});

it('null exporter accepts calls as no-op', function () {
    $exporter = new NullExporter;

    config(['agenticgraph.tracing.enabled' => true]);

    $exporter->startTrace('exec_null', 'graph');
    $exporter->addSpan(['id' => 'span_1', 'trace_id' => 'exec_null']);
    $exporter->completeTrace('exec_null', [], 100.0);

    expect($exporter->getTraceStartTime('exec_null'))->toBeNull();
});

it('container binds tracer exporter contract', function () {
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'log',
    ]);

    expect(app(TracerExporter::class))->toBeInstanceOf(LogExporter::class);
});
