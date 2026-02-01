<?php

namespace Shahzad\AgenticGraph\Tests\Unit\Tracing;

use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\Instrumentation\HttpClientInstrumentation;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;

class HttpMockPulseExporter extends PulseExporter
{
    public array $spans = [];

    public bool $enabled = true;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function addSpan(array $span): void
    {
        $this->spans[] = $span;
    }
}

it('captures http spans from client events', function () {
    config()->set('agenticgraph.tracing.enabled', true);

    $exporter = new HttpMockPulseExporter;
    $instrumentation = new HttpClientInstrumentation($exporter);

    $executionId = 'exec_http_1';
    app()->instance(ExecutionClock::class, new ExecutionClock($executionId));
    app()->instance(TraceContext::class, new TraceContext($executionId, 'span_root'));

    $psrRequest = new \GuzzleHttp\Psr7\Request('GET', 'https://example.com/convert?token=secret');
    $request = new Request($psrRequest);
    $response = new \Illuminate\Http\Client\Response(
        new \GuzzleHttp\Psr7\Response(200, [], json_encode(['ok' => true]))
    );

    $instrumentation->onRequestSending(new RequestSending($request));
    $instrumentation->onResponseReceived(new ResponseReceived($request, $response));

    expect($exporter->spans)->toHaveCount(1);
    $span = $exporter->spans[0];

    expect($span['span_type'])->toBe('http');
    expect($span['trace_id'])->toBe($executionId);
    expect($span['parent_id'])->toBe('span_root');
    expect($span['http']['method'])->toBe('GET');
    expect($span['http']['status_code'])->toBe(200);
});
