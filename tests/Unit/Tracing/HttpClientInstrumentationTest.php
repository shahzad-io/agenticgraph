<?php

namespace Shahzad\AgenticGraph\Tests\Unit\Tracing;

use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Shahzad\AgenticGraph\Execution\ExecutionContextManager;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\PrismStreamAdapter;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Tests\Support\ConsumeOnceStream;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
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

function bindHttpInstrumentationContext(string $executionId = 'exec_http_1'): array
{
    config()->set('agenticgraph.tracing.enabled', true);
    config()->set('agenticgraph.tracing.capture.http', true);

    $exporter = new HttpMockPulseExporter;
    $instrumentation = new HttpClientInstrumentation($exporter);

    app()->instance(ExecutionClock::class, new ExecutionClock($executionId));
    app()->instance(TraceContext::class, new TraceContext($executionId, 'span_root'));

    return [$instrumentation, $exporter];
}

function makeBufferedHttpResponse(string $body = '{"ok":true}'): \Illuminate\Http\Client\Response
{
    return new \Illuminate\Http\Client\Response(
        new Response(200, ['Content-Type' => 'application/json'], $body)
    );
}

function makeSseHttpResponse(string $sseBody): \Illuminate\Http\Client\Response
{
    return new \Illuminate\Http\Client\Response(
        new Response(200, ['Content-Type' => 'text/event-stream'], new ConsumeOnceStream($sseBody))
    );
}

function makeHttpRequest(): Request
{
    return new Request(new \GuzzleHttp\Psr7\Request('POST', 'https://api.openai.com/v1/responses'));
}

function readStreamBodyIncrementally(\Illuminate\Http\Client\Response $response): string
{
    $body = $response->toPsrResponse()->getBody();
    $content = '';

    while (! $body->eof()) {
        $chunk = $body->read(1024);
        if ($chunk === '') {
            break;
        }
        $content .= $chunk;
    }

    return $content;
}

it('captures http spans from client events', function () {
    [$instrumentation, $exporter] = bindHttpInstrumentationContext();

    $request = makeHttpRequest();
    $response = makeBufferedHttpResponse();

    $instrumentation->onRequestSending(new RequestSending($request));
    $instrumentation->onResponseReceived(new ResponseReceived($request, $response));

    expect($exporter->spans)->toHaveCount(1);
    $span = $exporter->spans[0];

    expect($span['span_type'])->toBe('http');
    expect($span['trace_id'])->toBe('exec_http_1');
    expect($span['parent_id'])->toBe('span_root');
    expect($span['status_code'])->toBe('OK')
        ->and($span['attributes'])->toBe([
            'http.request.method' => 'POST',
            'laravel.http.url_summary' => 'https://api.openai.com/v1/responses',
            'http.response.status_code' => 200,
            'laravel.http.response_size' => strlen('{"ok":true}'),
        ])
        ->and($span)->not->toHaveKey('http');
});

it('sets status_code ERROR and keeps the http status as an integer attribute on a 5xx', function (bool $withStart) {
    [$instrumentation, $exporter] = bindHttpInstrumentationContext();
    $request = makeHttpRequest();
    $response = new \Illuminate\Http\Client\Response(new Response(503, [], 'down'));

    if ($withStart) {
        $instrumentation->onRequestSending(new RequestSending($request));
    }
    $instrumentation->onResponseReceived(new ResponseReceived($request, $response));

    expect($exporter->spans[0]['status_code'])->toBe('ERROR')
        ->and($exporter->spans[0]['status'])->toBe('failed')
        ->and($exporter->spans[0]['attributes']['http.response.status_code'])->toBe(503)
        ->and($exporter->spans[0])->not->toHaveKey('http');
})->with(['with request start' => true, 'fallback without start' => false]);

it('puts a connection failure in the top-level error with status_code ERROR', function (bool $withStart) {
    [$instrumentation, $exporter] = bindHttpInstrumentationContext();
    $request = makeHttpRequest();

    if ($withStart) {
        $instrumentation->onRequestSending(new RequestSending($request));
    }
    $instrumentation->onConnectionFailed(new ConnectionFailed(
        $request,
        new ConnectionException('Connection refused'),
    ));

    $span = $exporter->spans[0];
    expect($span['status_code'])->toBe('ERROR')
        ->and($span['error'])->toBe(['type' => ConnectionException::class, 'message' => 'Connection refused'])
        ->and($span['attributes'])->toBe([
            'http.request.method' => 'POST',
            'laravel.http.url_summary' => 'https://api.openai.com/v1/responses',
        ])
        ->and($span)->not->toHaveKey('http');
})->with(['with request start' => true, 'fallback without start' => false]);

it('redacts credentials from url.full in full url capture', function () {
    [$instrumentation, $exporter] = bindHttpInstrumentationContext();
    config()->set('agenticgraph.tracing.capture.http_urls', 'full');
    $request = new Request(new \GuzzleHttp\Psr7\Request('GET', 'https://user:secret@api.example.com/v1/rates?q=1'));

    $instrumentation->onRequestSending(new RequestSending($request));
    $instrumentation->onResponseReceived(new ResponseReceived($request, makeBufferedHttpResponse()));

    expect($exporter->spans[0]['attributes']['url.full'])->toBe('https://REDACTED:REDACTED@api.example.com/v1/rates?q=1')
        ->and($exporter->spans[0]['attributes'])->not->toHaveKey('laravel.http.url_summary');
});

it('redacts sensitive query values in url.full and keeps the rest', function (string $url, string $expected) {
    [$instrumentation, $exporter] = bindHttpInstrumentationContext();
    config()->set('agenticgraph.tracing.capture.http_urls', 'full');
    $request = new Request(new \GuzzleHttp\Psr7\Request('GET', $url));

    $instrumentation->onResponseReceived(new ResponseReceived($request, makeBufferedHttpResponse()));

    expect($exporter->spans[0]['attributes']['url.full'])->toBe($expected);
})->with([
    'key' => ['https://api.example.com/v1/rates?key=abc', 'https://api.example.com/v1/rates?key=REDACTED'],
    'API_KEY, any case' => ['https://api.example.com/v1/rates?API_KEY=abc', 'https://api.example.com/v1/rates?API_KEY=REDACTED'],
    'harmless kept' => ['https://api.example.com/v1/rates?city=Lisbon&units=metric', 'https://api.example.com/v1/rates?city=Lisbon&units=metric'],
    'mixed, OTel list, fragment' => [
        'https://s3.example.com/f?city=Lisbon&X-Amz-Signature=s1&sig=s2&Token=t#key=frag',
        'https://s3.example.com/f?city=Lisbon&X-Amz-Signature=REDACTED&sig=REDACTED&Token=REDACTED#key=frag',
    ],
]);

it('guard: emits no url.full unless http url capture is full', function (string $mode) {
    [$instrumentation, $exporter] = bindHttpInstrumentationContext();
    config()->set('agenticgraph.tracing.capture.http_urls', $mode);
    $request = new Request(new \GuzzleHttp\Psr7\Request('GET', 'https://api.example.com/v1/rates?key=abc'));

    $instrumentation->onResponseReceived(new ResponseReceived($request, makeBufferedHttpResponse()));

    expect($exporter->spans[0]['attributes'])->not->toHaveKey('url.full')
        ->and(json_encode($exporter->spans[0]))->not->toContain('abc');
})->with(['summary', 'none']);

it('does not drain streamed sse bodies when execution clock is active', function () {
    [$instrumentation] = bindHttpInstrumentationContext('exec_sse_1');
    $sseBody = "data: {\"delta\":\"Hello\"}\n\ndata: {\"delta\":\" world\"}\n\ndata: [DONE]\n\n";

    $request = makeHttpRequest();
    $response = makeSseHttpResponse($sseBody);

    $instrumentation->onRequestSending(new RequestSending($request));
    $instrumentation->onResponseReceived(new ResponseReceived($request, $response));

    expect(readStreamBodyIncrementally($response))->toBe($sseBody);
});

it('omits response_size for streamed bodies without content-length', function () {
    [$instrumentation, $exporter] = bindHttpInstrumentationContext('exec_sse_2');

    $request = makeHttpRequest();
    $response = makeSseHttpResponse("data: {}\n\n");

    $instrumentation->onRequestSending(new RequestSending($request));
    $instrumentation->onResponseReceived(new ResponseReceived($request, $response));

    expect($exporter->spans)->toHaveCount(1)
        ->and($exporter->spans[0]['attributes'])->not->toHaveKey('laravel.http.response_size');
});

it('uses content-length for streamed bodies without draining', function () {
    [$instrumentation, $exporter] = bindHttpInstrumentationContext('exec_sse_3');

    $sseBody = "data: {}\n\n";
    $psrResponse = new Response(
        200,
        [
            'Content-Type' => 'text/event-stream',
            'Content-Length' => (string) strlen($sseBody),
        ],
        new ConsumeOnceStream($sseBody)
    );
    $response = new \Illuminate\Http\Client\Response($psrResponse);
    $request = makeHttpRequest();

    $instrumentation->onRequestSending(new RequestSending($request));
    $instrumentation->onResponseReceived(new ResponseReceived($request, $response));

    expect($exporter->spans[0]['attributes']['laravel.http.response_size'])->toBe(strlen($sseBody))
        ->and(readStreamBodyIncrementally($response))->toBe($sseBody);
});

it('keeps tracing and streaming compatible by preserving sse for prism reads', function () {
    [$instrumentation] = bindHttpInstrumentationContext('exec_sse_4');
    $sseBody = "data: {\"delta\":\"token-a\"}\n\ndata: {\"delta\":\"token-b\"}\n\n";

    $request = makeHttpRequest();
    $response = makeSseHttpResponse($sseBody);

    $instrumentation->onRequestSending(new RequestSending($request));
    $instrumentation->onResponseReceived(new ResponseReceived($request, $response));

    $preserved = readStreamBodyIncrementally($response);
    preg_match_all('/"delta":"([^"]+)"/', $preserved, $matches);

    $session = new StreamSession(
        streamId: 'stream-tracing',
        executionId: 'exec_sse_4',
        options: new StreamOptions,
        startedAtUnix: time()
    );
    $sink = new MemorySink;
    $adapter = new PrismStreamAdapter(
        session: $session,
        nodeName: 'llm',
        spanId: 'span-llm',
        parentSpanId: 'span-root'
    );

    foreach ($matches[1] as $delta) {
        $mapped = $adapter->adapt(new TextDeltaEvent('evt-delta', time(), $delta, 'msg-1'));
        if ($mapped !== null) {
            $sink->emit($mapped);
        }
    }

    $deltaEvents = array_values(array_filter(
        iterator_to_array($sink->events()),
        fn (StreamEventImpl $event) => $event->type() === 'text_delta'
    ));

    expect($deltaEvents)->toHaveCount(2)
        ->and($deltaEvents[0]->payload()['delta'])->toBe('token-a')
        ->and($deltaEvents[1]->payload()['delta'])->toBe('token-b');
});

function registerTracingThroughProvider(HttpMockPulseExporter $exporter): void
{
    app()->instance(TracerExporter::class, $exporter);
    app()->forgetInstance(ExecutionContextManager::class);
}

it('matches responses to requests through the event dispatcher', function () {
    [, $exporter] = bindHttpInstrumentationContext('exec_http_dispatch');
    registerTracingThroughProvider($exporter);
    Log::spy();

    $request = makeHttpRequest();
    event(new RequestSending($request));
    event(new ResponseReceived($request, makeBufferedHttpResponse()));

    expect(app(HttpClientInstrumentation::class))->toBe(app(HttpClientInstrumentation::class))
        ->and($exporter->spans)->toHaveCount(1)
        ->and($exporter->spans[0]['started_at'])->toMatch(TRACE_TIME_PATTERN);
    Log::shouldNotHaveReceived('warning', fn (string $message) => $message === 'HTTP response without matching request');
});

it('cleans up stored request starts when the execution exits', function () {
    config()->set('agenticgraph.tracing.enabled', true);
    config()->set('agenticgraph.tracing.capture.http', true);
    $exporter = new HttpMockPulseExporter;
    registerTracingThroughProvider($exporter);

    $manager = app(ExecutionContextManager::class);
    $scope = $manager->enter('exec_http_cleanup', 'graph', new ExecutionOptions);
    event(new RequestSending(makeHttpRequest()));

    $instrumentation = app(HttpClientInstrumentation::class);
    $requestStarts = fn () => $this->requestStarts;
    expect($requestStarts->call($instrumentation))->toHaveKey('exec_http_cleanup');

    $manager->exit($scope);

    expect($requestStarts->call($instrumentation))->toBe([]);
});

it('leaves the connection failure message out when capture.errors is class', function () {
    config(['agenticgraph.tracing.capture.errors' => 'class']);
    [$instrumentation, $exporter] = bindHttpInstrumentationContext();
    $request = makeHttpRequest();

    $instrumentation->onRequestSending(new RequestSending($request));
    $instrumentation->onConnectionFailed(new ConnectionFailed($request, new ConnectionException('cURL error for https://user:pw@host')));

    expect($exporter->spans[0]['error'])->toBe(['type' => ConnectionException::class]);
});
