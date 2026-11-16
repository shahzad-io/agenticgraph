<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Shahzad\AgenticGraph\Jobs\SendPulseSegmentJob;
use Shahzad\AgenticGraph\Tracing\PulseExporter;

beforeEach(function () {
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.endpoint' => 'https://ingest.test',
        'agenticgraph.tracing.api_key' => 'test-key',
        'queue.default' => 'realq',
        'queue.connections.realq' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
    ]);
    app()->forgetInstance('request');
});

it('hands the final segment to a queued job and does not wait for a slow endpoint', function () {
    Queue::fake();
    Http::fake(function () {
        usleep(1_500_000);

        return Http::response([], 202);
    });

    $exporter = new PulseExporter;
    $exporter->startTrace('tr_q', 'g');
    $start = microtime(true);
    $exporter->completeTrace('tr_q', [], 10.0);
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(0.5);
    Http::assertNothingSent();
    Queue::assertPushed(SendPulseSegmentJob::class, fn (SendPulseSegmentJob $job): bool => $job->delay === null
        && $job->traceId === 'tr_q'
        && str_starts_with($job->segmentId, 'seg_')
        && json_decode($job->body, true)['trace']['id'] === 'tr_q');
});

it('posts inline on the sync queue', function () {
    config(['queue.default' => 'sync']);
    Queue::fake();
    Http::fake(['ingest.test/*' => Http::response([], 202)]);

    $exporter = new PulseExporter;
    $exporter->startTrace('tr_s', 'g');
    $exporter->completeTrace('tr_s', [], 10.0);

    Http::assertSentCount(1);
    Queue::assertNothingPushed();
});
