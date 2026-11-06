<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Tracing\PulseExporter;

beforeEach(function () {
    config()->set('agenticgraph.tracing.enabled', true);
    config()->set('agenticgraph.tracing.api_key', 'ap_test_xxx');
    Http::fake();
});

it('refuses a plain http endpoint, sends nothing and names the setting', function () {
    config()->set('agenticgraph.tracing.endpoint', 'http://ingest.example.com');
    Log::spy();

    $exporter = new PulseExporter;
    $exporter->startTrace('exec_http', 'g');
    $exporter->completeTrace('exec_http');
    $exporter->flush('exec_http');

    expect($exporter->isEnabled())->toBeFalse();
    Http::assertNothingSent();
    Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'AGENTICPULSE_ENDPOINT') && str_contains($message, 'https'))->atLeast()->once();
});

it('accepts https and local http endpoints', function (string $endpoint) {
    config()->set('agenticgraph.tracing.endpoint', $endpoint);

    expect((new PulseExporter)->isEnabled())->toBeTrue();
})->with([
    'https' => 'https://ingest.agenticgraph.dev',
    'localhost' => 'http://localhost:8000',
    '127.0.0.1' => 'http://127.0.0.1:8000',
    '.test host' => 'http://pulse.test',
]);
