<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Shahzad\AgenticGraph\Tracing\PulseExporter;

beforeEach(function () {
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.endpoint' => 'https://ingest.test',
        'agenticgraph.tracing.api_key' => 'test-key',
    ]);
    app()->forgetInstance('request');
});

function ptSendOneTrace(): void
{
    $exporter = new PulseExporter;
    $exporter->startTrace('tr_transport', 'g');
    $exporter->completeTrace('tr_transport', [], 10.0);
}

it('retries a 503 once after 500 ms and stops on the 202', function () {
    Sleep::fake();
    Http::fake(['ingest.test/*' => Http::sequence()->push([], 503)->push(['status' => 'captured'], 202)]);

    ptSendOneTrace();

    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([Sleep::for(500)->milliseconds()]);
});

it('never retries a 4xx and logs the response code', function () {
    Sleep::fake();
    Log::spy();
    Http::fake(['ingest.test/*' => Http::response(['code' => 'quota_exceeded', 'message' => 'Monthly quota exceeded'], 429)]);

    ptSendOneTrace();

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
    Log::shouldHaveReceived('warning')->once()->with('AgenticPulse ingest failed', Mockery::on(
        fn (array $context): bool => $context['status'] === 429 && $context['code'] === 'quota_exceeded'
    ));
});

it('retries a connection error once and then logs a warning with code connection_error', function () {
    Sleep::fake();
    Log::spy();
    $attempts = 0;
    Http::fake(function ($request) use (&$attempts) {
        $attempts++;

        return Factory::failedConnection()($request);
    });

    ptSendOneTrace();

    expect($attempts)->toBe(2);
    Sleep::assertSleptTimes(1);
    Log::shouldHaveReceived('warning')->once()->with('AgenticPulse ingest failed', Mockery::on(
        fn (array $context): bool => $context['code'] === 'connection_error'
    ));
});

it('defaults the endpoint to ingest.agenticgraph.dev', function () {
    config(['agenticgraph.tracing' => Arr::except(config('agenticgraph.tracing'), 'endpoint')]);
    Http::fake(['*' => Http::response([], 202)]);

    ptSendOneTrace();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://ingest.agenticgraph.dev/v1/ingest');
});

/**
 * A real HTTP server whose ingest route sleeps before answering 202. Returns [base url, stop].
 *
 * @return array{0: string, 1: callable(): void, 2: string}
 */
function ptSlowServer(float $sleepSeconds): array
{
    $dir = sys_get_temp_dir().'/ag-transport-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $log = $dir.'/hits.log';
    file_put_contents($dir.'/router.php', '<?php
        usleep('.(int) ($sleepSeconds * 1_000_000).');
        file_put_contents('.var_export($log, true).', strlen(file_get_contents("php://input")).PHP_EOL, FILE_APPEND);
        http_response_code(202);
        header("Content-Type: application/json");
        echo "{\"status\":\"captured\"}";
    ');

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
    fclose($socket);

    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $dir.'/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
        usleep(100_000);
    }

    return ["http://127.0.0.1:{$port}", function () use ($process, $dir): void {
        proc_terminate($process);
        proc_close($process);
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }, $log];
}

it('guard: a 1 MB segment to a server that answers in 2.5 s succeeds in one attempt', function () {
    [$url, $stop, $log] = ptSlowServer(2.5);
    config(['agenticgraph.tracing.endpoint' => $url]);
    Log::spy();

    try {
        $exporter = new PulseExporter;
        $exporter->startTrace('tr_big_slow', 'g');
        for ($i = 0; $i < 200; $i++) {
            $exporter->addSpan(['id' => "span_{$i}", 'trace_id' => 'tr_big_slow', 'span_type' => 'node', 'blob' => str_repeat('x', 5_000)]);
        }
        $started = microtime(true);
        $exporter->completeTrace('tr_big_slow', [], 10.0);
        $elapsed = microtime(true) - $started;

        $hits = array_map('intval', file($log, FILE_IGNORE_NEW_LINES) ?: []);
        expect($hits)->toHaveCount(1)
            ->and($hits[0])->toBeGreaterThan(900_000)
            ->and($elapsed)->toBeLessThan(3.0);
        Log::shouldNotHaveReceived('warning');
    } finally {
        $stop();
    }
});

it('guard: a server slower than the configured timeout is tried twice and stays inside the 7 s budget', function () {
    [$url, $stop, $log] = ptSlowServer(2.5);
    config(['agenticgraph.tracing.endpoint' => $url, 'agenticgraph.tracing.transport.timeout' => 1]);
    Log::spy();

    try {
        $started = microtime(true);
        ptSendOneTrace();
        $elapsed = microtime(true) - $started;

        expect($elapsed)->toBeGreaterThan(2.4)
            ->and($elapsed)->toBeLessThan(7.0);
        Log::shouldHaveReceived('warning')->once()->with('AgenticPulse ingest failed', Mockery::on(
            fn (array $context): bool => $context['code'] === 'connection_error'
        ));
    } finally {
        $stop();
    }
});
