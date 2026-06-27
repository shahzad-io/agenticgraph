<?php

namespace Shahzad\AgenticGraph\Tests\Unit\Tracing;

use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\PrismStreamAdapter;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Tests\Support\ConsumeOnceStream;
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
    expect($span['http']['method'])->toBe('POST');
    expect($span['http']['status_code'])->toBe(200);
    expect($span['http']['response_size'])->toBe(strlen('{"ok":true}'));
});

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
        ->and($exporter->spans[0]['http'])->not->toHaveKey('response_size');
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

    expect($exporter->spans[0]['http']['response_size'])->toBe(strlen($sseBody))
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
