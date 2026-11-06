<?php

use Illuminate\Http\Request;
use Shahzad\AgenticGraph\Http\Controllers\StreamController;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;

function sseCustomEvent(string $streamId, int $seq, string $type, array $payload = []): StreamEventImpl
{
    return new StreamEventImpl($streamId, 'exec-custom', 'retrieve', null, null, $type, $payload, $seq, $seq, new DateTimeImmutable('2024-01-01T00:00:00Z'));
}

function sseCustomFrames(string $streamId, array $query = []): string
{
    config()->set('agenticgraph.streaming.sse.max_duration_seconds', 2);
    config()->set('agenticgraph.streaming.sse.poll_interval_ms', 20);
    config()->set('agenticgraph.streaming.sse.heartbeat_interval', 0);
    $response = (new StreamController)->stream($streamId, Request::create('/streams/'.$streamId, 'GET', $query), app(StreamStoreInterface::class));
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    app()->forgetInstance(StreamStoreInterface::class);
    $store = app(StreamStoreInterface::class);
    $store->append(sseCustomEvent('s-custom', 1, 'node_start', ['node_name' => 'retrieve']));
    $store->append(sseCustomEvent('s-custom', 2, 'sources', ['ids' => [1]]));
    $store->append(sseCustomEvent('s-custom', 3, 'text_delta', ['delta' => 'a']));
    $store->append(sseCustomEvent('s-custom', 4, 'execution_terminal', ['status' => 'completed', 'final_state' => null]));
});

it('keeps the author event name in the frame without the parameter', function () {
    $content = sseCustomFrames('s-custom');

    expect($content)->toContain("id: 2\nevent: sources\n")
        ->and($content)->not->toContain('custom_event')
        ->and($content)->not->toContain('event_type');
});

it('wraps only custom events in event: custom_event with the name in event_type when asked', function () {
    $content = sseCustomFrames('s-custom', ['custom_events' => 'wrap']);

    expect($content)->toContain("id: 2\nevent: custom_event\n")
        ->and($content)->toContain('"event_type":"sources"')
        ->and($content)->toContain('"payload":{"ids":[1]}')
        ->and($content)->toContain("id: 1\nevent: node_start\n")
        ->and($content)->toContain("id: 3\nevent: text_delta\n")
        ->and($content)->toContain("id: 4\nevent: execution_terminal\n")
        ->and($content)->not->toContain("event: sources\n");
});

it('wraps on a cursor replay too', function () {
    $content = sseCustomFrames('s-custom', ['custom_events' => 'wrap', 'cursor' => 1]);

    expect($content)->toContain("id: 2\nevent: custom_event\n")
        ->and($content)->not->toContain('event: node_start');
});

it('ignores any other value of the parameter', function () {
    expect(sseCustomFrames('s-custom', ['custom_events' => 'yes']))->toContain("event: sources\n");
});
