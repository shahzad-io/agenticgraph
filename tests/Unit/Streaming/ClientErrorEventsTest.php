<?php

use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\ToolResult;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\PrismStreamAdapter;
use Shahzad\AgenticGraph\Streaming\StreamErrorHandler;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;

const CLIENT_ERROR_RAW = 'You exceeded your current quota, key sk-live-123 org org-9 at https://api.internal.example/v1';

function clientErrorAdapted(string $errorType, ?array $metadata = ['request_id' => 'req_secret']): array
{
    $adapter = new PrismStreamAdapter(new StreamSession('s', 'e', new StreamOptions, time()), 'node', null, null);
    $event = $adapter->adapt(new ErrorEvent('id', 1700000000, $errorType, CLIENT_ERROR_RAW, true, $metadata));

    return $event->payload();
}

function clientErrorStreamPayload(string $stage = 'store'): array
{
    $event = new StreamEventImpl('s', 'e', 'node-a', null, null, 'text_delta', ['delta' => 'a'], 7, 3, new DateTimeImmutable('now'));
    $payload = (new StreamErrorHandler)->decide(new RuntimeException('Redis connection lost at 10.0.0.5:6379 password=hunter2'), $event, $stage)->payload;

    return $payload->jsonSerialize();
}

it('sends a fixed category and generic text for a Prism error event by default', function (string $providerType, string $category) {
    $payload = clientErrorAdapted($providerType);

    expect($payload['error_type'])->toBe($category)
        ->and($payload['recoverable'])->toBeTrue()
        ->and($payload['metadata'])->toBeNull()
        ->and($payload['timestamp'])->toBe(1700000000)
        ->and(json_encode($payload))->not->toContain('sk-live')
        ->and(json_encode($payload))->not->toContain('internal.example')
        ->and(json_encode($payload))->not->toContain('req_secret')
        ->and(json_encode($payload))->not->toContain($providerType === 'rate_limit_exceeded' ? 'quota' : 'exceeded');
})->with([
    'rate limit' => ['rate_limit_exceeded', 'rate_limited'],
    'rate limit error' => ['rate_limit_error', 'rate_limited'],
    'overloaded' => ['overloaded_error', 'provider_unavailable'],
    'server error' => ['server_error', 'provider_unavailable'],
    'timeout' => ['request_timeout', 'provider_unavailable'],
    'invalid request' => ['invalid_request_error', 'provider_error'],
    'unknown' => ['unknown_error', 'provider_error'],
    'exception class' => [RuntimeException::class, 'provider_error'],
]);

it('keeps the category message fixed, not the provider text', function () {
    expect(clientErrorAdapted('rate_limit_exceeded')['message'])->toBe('The model provider is rate limiting requests')
        ->and(clientErrorAdapted('overloaded_error')['message'])->toBe('The model provider is unavailable')
        ->and(clientErrorAdapted('anything')['message'])->toBe('The model provider returned an error');
});

it('sends the provider type, message and metadata of a Prism error event when details are on', function () {
    config()->set('agenticgraph.streaming.expose_error_details', true);

    $payload = clientErrorAdapted('rate_limit_exceeded');

    expect($payload['error_type'])->toBe('rate_limit_exceeded')
        ->and($payload['message'])->toBe(CLIENT_ERROR_RAW)
        ->and($payload['metadata'])->toBe(['request_id' => 'req_secret']);
});

it('cuts a provider message to capture.max_length and honors capture.errors=class when details are on', function () {
    config()->set('agenticgraph.streaming.expose_error_details', true);
    config()->set('agenticgraph.tracing.capture.max_length', 50);

    $cut = clientErrorAdapted('rate_limit_exceeded')['message'];
    config()->set('agenticgraph.tracing.capture.errors', 'class');
    $class = clientErrorAdapted('rate_limit_exceeded');

    expect(strlen($cut))->toBeLessThanOrEqual(50)
        ->and($cut)->toContain('[...truncated '.strlen(CLIENT_ERROR_RAW).']')
        ->and($class['error_type'])->toBe('rate_limit_exceeded')
        ->and($class['message'])->not->toContain('sk-live');
});

it('sends generic values on stream_error by default and keeps the package-owned fields', function () {
    $payload = clientErrorStreamPayload('store');

    expect($payload)->toMatchArray([
        'error_type' => 'StreamError',
        'message' => 'Stream error',
        'stage' => 'store',
        'event_type' => 'text_delta',
        'event_seq' => 7,
        'event_node_name' => 'node-a',
        'event_node_seq' => 3,
    ])->and(json_encode($payload))->not->toContain('hunter2')
        ->and(json_encode($payload))->not->toContain('RuntimeException');
});

it('sends the class and message on stream_error when details are on, cut and honoring capture.errors', function () {
    config()->set('agenticgraph.streaming.expose_error_details', true);
    $on = clientErrorStreamPayload();

    config()->set('agenticgraph.tracing.capture.max_length', 50);
    $cut = clientErrorStreamPayload()['message'];

    config()->set('agenticgraph.tracing.capture.errors', 'class');
    $class = clientErrorStreamPayload();

    expect($on['error_type'])->toBe(RuntimeException::class)
        ->and($on['message'])->toContain('hunter2')
        ->and(strlen($cut))->toBeLessThanOrEqual(50)
        ->and($class['error_type'])->toBe(RuntimeException::class)
        ->and($class['message'])->toBe('Stream error');
});

const TOOL_ERROR_RAW = 'SQLSTATE[HY000] connection to 10.0.0.5 refused, user=app password=hunter2';

function toolResultPayload(bool $success, ?string $error = TOOL_ERROR_RAW): array
{
    $adapter = new PrismStreamAdapter(new StreamSession('s', 'e', new StreamOptions, time()), 'node', null, null);
    $result = new ToolResult('call_1', 'lookup', ['q' => 'a'], $success ? 'found a' : (string) $error);

    return $adapter->adapt(new ToolResultEvent('id', 1700000000, $result, 'msg_1', $success, $error))->payload();
}

it('sends "Tool failed" for a failed tool_result by default, in error and in result', function () {
    $payload = toolResultPayload(false);

    expect($payload)->toMatchArray(['tool_id' => 'call_1', 'tool_name' => 'lookup', 'message_id' => 'msg_1', 'success' => false, 'error' => 'Tool failed', 'result' => 'Tool failed'])
        ->and(json_encode($payload))->not->toContain('hunter2')
        ->and(json_encode($payload))->not->toContain('SQLSTATE');
});

it('leaves a successful tool_result unchanged', function () {
    expect(toolResultPayload(true, null))->toMatchArray(['success' => true, 'error' => null, 'result' => 'found a']);
});

it('does not change what the model receives: the Prism event and tool result keep the real error', function () {
    $result = new ToolResult('call_1', 'lookup', [], TOOL_ERROR_RAW);
    $event = new ToolResultEvent('id', 1700000000, $result, 'msg_1', false, TOOL_ERROR_RAW);
    (new PrismStreamAdapter(new StreamSession('s', 'e', new StreamOptions, time()), 'node', null, null))->adapt($event);

    expect($event->error)->toBe(TOOL_ERROR_RAW)
        ->and($event->toolResult->result)->toBe(TOOL_ERROR_RAW);
});

it('sends the tool error when details are on, cut to capture.max_length, and "Tool failed" with capture.errors=class', function () {
    config()->set('agenticgraph.streaming.expose_error_details', true);
    $on = toolResultPayload(false);

    config()->set('agenticgraph.tracing.capture.max_length', 50);
    $cut = toolResultPayload(false)['error'];

    config()->set('agenticgraph.tracing.capture.errors', 'class');
    $class = toolResultPayload(false);

    expect($on['error'])->toBe(TOOL_ERROR_RAW)
        ->and($on['result'])->toBe(TOOL_ERROR_RAW)
        ->and(strlen($cut))->toBeLessThanOrEqual(50)
        ->and($cut)->toContain('[...truncated '.strlen(TOOL_ERROR_RAW).']')
        ->and($class['error'])->toBe('Tool failed')
        ->and($class['result'])->toBe('Tool failed');
});
