<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Shahzad\AgenticGraph\Tracing\CaptureDepth;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TracePayloadBuilder;

beforeEach(function () {
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.endpoint' => 'https://ingest.test',
        'agenticgraph.tracing.api_key' => 'test-key',
    ]);
    app()->forgetInstance('request');
});

it('measures the encoded bytes: non-ASCII text that estimates under 1 MiB is still truncated', function () {
    // 200,000 x 2 bytes = 400 KB by strlen, but 1.2 MB once encoded as é.
    $calls = [['id' => 'c1', 'trace_id' => 'tr_1', 'prompt' => 'p', 'response' => str_repeat('é', 200_000)]];

    $payload = (new TracePayloadBuilder)->build(['id' => 'tr_1', 'status' => 'completed'], [], $calls);

    expect($payload['_meta']['truncated'])->toBeTrue()
        ->and($payload['_meta']['original_size_bytes'])->toBeGreaterThan(1_048_576)
        ->and(strlen(TracePayloadBuilder::encode($payload)))->toBeLessThanOrEqual(1_048_576 + 2_000);
});

it('encode is exactly the body Pulse posts', function () {
    Http::fake(['ingest.test/*' => Http::response([], 202)]);
    $exporter = new PulseExporter;
    $exporter->startTrace('tr_enc', 'g');
    $exporter->addSpan(['id' => 's1', 'trace_id' => 'tr_enc', 'span_type' => 'node', 'duration_ms' => 1.0, 'note' => "caf\u{e9} a/b"]);
    $exporter->completeTrace('tr_enc', [], 10.0);

    $body = Http::recorded()->first()[0]->body();
    expect(TracePayloadBuilder::encode(json_decode($body, true)))->toBe(json_encode(json_decode($body, true), JSON_PRESERVE_ZERO_FRACTION));
});

it('a multibyte value cut at the limit is cut at a character boundary, byte for byte, and the segment is sent', function () {
    Http::fake(['ingest.test/*' => Http::response([], 202)]);
    config(['agenticgraph.tracing.capture.max_length' => 100]);
    $cut = CaptureDepth::truncate(str_repeat('é', 3000)); // 6000 bytes; the 20-byte marker leaves 80 bytes = 40 characters
    $expected = str_repeat('é', 40).' [...truncated 6000]';

    $exporter = new PulseExporter;
    $exporter->startTrace('tr_mb', 'g');
    $exporter->addSpan(['id' => 's1', 'trace_id' => 'tr_mb', 'span_type' => 'node', 'duration_ms' => 1.0, 'attributes' => ['v' => $cut]]);
    $exporter->completeTrace('tr_mb', [], 10.0);

    Http::assertSentCount(1);
    $sent = json_decode(Http::recorded()->first()[0]->body(), true)['spans'][0]['attributes']['v'];
    expect($cut)->toBe($expected)
        ->and($sent)->toBe($expected)
        ->and($sent)->not->toContain("\u{FFFD}");
});

it('a state value with an invalid UTF-8 byte still sends the segment, with U+FFFD in that value', function () {
    Http::fake(['ingest.test/*' => Http::response([], 202)]);

    $exporter = new PulseExporter;
    $exporter->startTrace('tr_bad', 'g');
    $exporter->addSpan(['id' => 's1', 'trace_id' => 'tr_bad', 'span_type' => 'node', 'duration_ms' => 1.0, 'attributes' => ['v' => "bad\xC3( byte", 'ok' => 'fine']]);
    $exporter->completeTrace('tr_bad', [], 10.0);

    Http::assertSentCount(1);
    $attributes = json_decode(Http::recorded()->first()[0]->body(), true)['spans'][0]['attributes'];
    expect($attributes['v'])->toBe("bad\u{FFFD}( byte")->and($attributes['ok'])->toBe('fine');
});
