<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Execution\NodeMiddlewarePipeline;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Jobs\ResumeExecutionJob;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;
use Shahzad\AgenticGraph\Tracing\Exporters\FileExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\OtlpExporter;
use Shahzad\AgenticGraph\Tracing\PulseExporter;

/**
 * An app that published config/agenticgraph.php before T8 lacks every key added since (spec M-6).
 * Each missing key must resolve to its code default, not null. The guard tests cover keys
 * that already resolved correctly before T8 (spec N-1).
 */
beforeEach(function (): void {
    config()->set('agenticgraph', [
        'tracing' => ['enabled' => true, 'api_key' => 'ag_test'],
        'execution' => ['timeout' => 300],
        'checkpoint' => ['enabled' => true],
    ]);
});

it('boots OTLP with the default endpoint', function (): void {
    expect((new OtlpExporter)->isEnabled())->toBeTrue()
        ->and((fn (): string => $this->endpoint)->call(new OtlpExporter))->toBe('http://localhost:4318/v1/traces');
});

it('queues every job on the default connection and queue', function (object $job): void {
    expect($job->connection)->toBe('redis')->and($job->queue)->toBe('agenticgraph');
})->with([
    'node' => fn () => new ExecuteNodeJob('exec_1', 'n', [], 'g'),
    'graph' => fn () => new ExecuteGraphJob('g', 'exec_1', []),
    'continue' => fn () => new ContinueExecutionJob('g', 'exec_1', [], 'n'),
    'resume' => fn () => new ResumeExecutionJob(1, null),
]);

it('gives the middleware pipeline an empty global list, not null', function (): void {
    expect((fn (): ?array => $this->globalMiddleware)->call(app(NodeMiddlewarePipeline::class)))->toBe([]);
});

it('guard: resolves storage from the code default', function (): void {
    expect(app(StorageInterface::class))->toBeInstanceOf(DatabaseStorage::class);
});

it('guard: posts Pulse to the default endpoint', function (): void {
    Http::fake();
    $exporter = new PulseExporter;
    $exporter->startTrace('exec_cfg', 'g', []);
    $exporter->completeTrace('exec_cfg');
    $exporter->flush('exec_cfg');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://ingest.agenticgraph.dev/v1/ingest');
});

it('guard: writes the file exporter to the default path', function (): void {
    $exporter = new FileExporter;

    expect($exporter->isEnabled())->toBeTrue()
        ->and((fn (): string => $this->path)->call($exporter))->toBe(storage_path('app/agenticgraph/traces'));
});
