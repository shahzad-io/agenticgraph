<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Shahzad\AgenticGraph\Tracing\PulseExporter;

beforeEach(function () {
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.endpoint' => 'https://api.example.com',
        'agenticgraph.tracing.api_key' => 'test-key',
    ]);
});

it('sends trace synchronously in queue context', function () {
    Http::fake([
        'api.example.com/*' => Http::response(['success' => true], 200),
    ]);

    // Simulate queue context by unbinding request
    app()->forgetInstance('request');

    $exporter = new PulseExporter;
    $exporter->startTrace('test-trace', 'test-graph');
    $exporter->addSpan([
        'id' => 'span-1',
        'trace_id' => 'test-trace',
        'node_name' => 'test-node',
        'span_type' => 'node',
        'status' => 'success',
        'started_at' => now()->toIso8601String(),
        'ended_at' => now()->toIso8601String(),
        'duration_ms' => 100,
    ]);

    // Complete trace - should send immediately in queue context
    $exporter->completeTrace('test-trace', [], 100);

    // Verify HTTP request was made synchronously
    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.example.com/v1/ingest'
            && $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

it('dispatches trace after response in HTTP context', function () {
    Http::fake([
        'api.example.com/*' => Http::response(['success' => true], 200),
    ]);

    // Simulate HTTP context by binding a request
    $request = Request::create('/test', 'GET');
    app()->instance('request', $request);

    $exporter = new PulseExporter;
    $exporter->startTrace('test-trace', 'test-graph');
    $exporter->addSpan([
        'id' => 'span-1',
        'trace_id' => 'test-trace',
        'node_name' => 'test-node',
        'span_type' => 'node',
        'status' => 'success',
        'started_at' => now()->toIso8601String(),
        'ended_at' => now()->toIso8601String(),
        'duration_ms' => 100,
    ]);

    // Complete trace - should dispatch job with afterResponse()
    $exporter->completeTrace('test-trace', [], 100);

    // In HTTP context, the job is dispatched but not executed yet
    // We can't easily test afterResponse() behavior in unit tests
    // but we can verify no immediate HTTP call was made
    Http::assertNothingSent();
});
