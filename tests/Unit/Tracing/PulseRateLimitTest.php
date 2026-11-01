<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Shahzad\AgenticGraph\Jobs\SendPulseSegmentJob;
use Shahzad\AgenticGraph\Tracing\PulseExporter;

beforeEach(function () {
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.endpoint' => 'https://ingest.test',
        'agenticgraph.tracing.api_key' => 'test-key',
        'queue.default' => 'sync',
        'queue.connections.realq' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
    ]);
    app()->forgetInstance('request');
    Sleep::fake();
});

function prlSend(): void
{
    $exporter = new PulseExporter;
    $exporter->startTrace('tr_rl', 'g');
    $exporter->completeTrace('tr_rl', [], 10.0);
}

function prlBodies(): array
{
    return array_map(fn (array $pair): string => $pair[0]->body(), Http::recorded()->all());
}

it('honors Retry-After in seconds with one in-process retry', function () {
    Http::fake(['ingest.test/*' => Http::sequence()->push([], 429, ['Retry-After' => '1'])->push([], 202)]);

    prlSend();

    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(1)->seconds()]);
    expect(prlBodies()[0])->toBe(prlBodies()[1]);
});

it('honors Retry-After as an HTTP date', function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $date = Carbon::now()->addSeconds(2)->utc()->format('D, d M Y H:i:s \G\M\T');
    Http::fake(['ingest.test/*' => Http::sequence()->push([], 429, ['Retry-After' => $date])->push([], 202)]);

    prlSend();

    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(2)->seconds()]);
    Carbon::setTestNow();
});

it('dispatches a delayed job for a long Retry-After when the app has a real queue', function () {
    config(['queue.default' => 'realq']);
    Queue::fake();
    Http::fake(['ingest.test/*' => Http::response([], 429, ['Retry-After' => '30'])]);

    prlSend();

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
    Queue::assertPushed(SendPulseSegmentJob::class, fn (SendPulseSegmentJob $job): bool => $job->delay === 30
        && $job->body === prlBodies()[0]
        && $job->traceId === 'tr_rl'
        && str_starts_with($job->segmentId, 'seg_'));
});

it('waits the cap and retries once for a long Retry-After on the sync queue', function () {
    Queue::fake();
    Http::fake(['ingest.test/*' => Http::sequence()->push([], 429, ['Retry-After' => '30'])->push([], 202)]);

    prlSend();

    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(2)->seconds()]);
    Queue::assertNothingPushed();
});

it('falls back to wait and retry once when the dispatch fails, for example an SQS size limit', function () {
    config(['queue.default' => 'realq']);
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('message too large'));
    app()->instance(Dispatcher::class, $dispatcher);
    Http::fake(['ingest.test/*' => Http::sequence()->push([], 429, ['Retry-After' => '30'])->push([], 202)]);

    prlSend();

    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(2)->seconds()]);
});

it('does not retry a 429 without Retry-After and logs the loss', function () {
    Log::spy();
    Queue::fake();
    Http::fake(['ingest.test/*' => Http::response(['code' => 'quota_exceeded'], 429)]);

    prlSend();

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
    Queue::assertNothingPushed();
    Log::shouldHaveReceived('warning')->once()->with('AgenticPulse ingest failed', Mockery::on(
        fn (array $c): bool => $c['status'] === 429 && $c['code'] === 'quota_exceeded'
    ));
});

it('retries a 429 in process only once', function () {
    Log::spy();
    Http::fake(['ingest.test/*' => Http::response([], 429, ['Retry-After' => '1'])]);

    prlSend();

    Http::assertSentCount(2);
    Log::shouldHaveReceived('warning')->once();
});

it('treats a 413 as a logged loss with no retry and no job', function () {
    Log::spy();
    config(['queue.default' => 'realq']);
    Queue::fake();
    Http::fake(['ingest.test/*' => Http::response(['code' => 'payload_too_large'], 413)]);

    prlSend();

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
    Queue::assertNothingPushed();
    Log::shouldHaveReceived('warning')->once()->with('AgenticPulse ingest failed', Mockery::on(fn (array $c): bool => $c['status'] === 413));
});

it('sends User-Agent and Accept on every request, retry included', function () {
    Http::fake(['ingest.test/*' => Http::sequence()->push([], 429, ['Retry-After' => '1'])->push([], 202)]);

    prlSend();

    $requests = array_map(fn (array $pair) => $pair[0], Http::recorded()->all());
    expect($requests)->toHaveCount(2);
    foreach ($requests as $request) {
        expect($request->header('User-Agent')[0] ?? '')->toMatch('/^agenticgraph\/\S+$/')
            ->and($request->header('Accept')[0] ?? '')->toBe('application/json');
    }
});

function prlJob(): SendPulseSegmentJob
{
    return (new SendPulseSegmentJob('{"k":1}', 'tr_job', 'seg_0123456789abcdef'))->withFakeQueueInteractions();
}

it('job: posts the stored body unchanged and finishes on 2xx', function () {
    Http::fake(['ingest.test/*' => Http::response([], 202)]);
    $job = prlJob();

    $job->handle();

    expect(prlBodies())->toBe(['{"k":1}']);
    $job->assertNotReleased();
});

it('job: releases a 429 with the Retry-After value', function () {
    Http::fake(['ingest.test/*' => Http::response([], 429, ['Retry-After' => '7'])]);
    $job = prlJob();

    $job->handle();

    Sleep::assertNeverSlept();
    $job->assertReleased(7);
});

it('job: releases a 5xx and a connection error', function () {
    Http::fake(['ingest.test/*' => Http::response([], 503)]);
    $job = prlJob();
    $job->handle();
    $job->assertReleased();

    Http::fake(fn ($request) => Factory::failedConnection()($request));
    $job = prlJob();
    $job->handle();
    $job->assertReleased();
});

it('job: logs another 4xx and does not release', function () {
    Log::spy();
    Http::fake(['ingest.test/*' => Http::response(['code' => 'forbidden'], 403)]);
    $job = prlJob();

    $job->handle();

    $job->assertNotReleased();
    Log::shouldHaveReceived('warning')->once()->with('AgenticPulse ingest failed', Mockery::on(fn (array $c): bool => $c['status'] === 403));
});

it('job: failed() logs the trace and segment id', function () {
    Log::spy();

    prlJob()->failed(new RuntimeException('gone'));

    Log::shouldHaveReceived('warning')->once()->with(Mockery::any(), Mockery::on(
        fn (array $c): bool => $c['trace_id'] === 'tr_job' && $c['segment_id'] === 'seg_0123456789abcdef'
    ));
});

it('job: takes tries, connection and queue from config', function () {
    config([
        'agenticgraph.tracing.transport.job_tries' => 9,
        'agenticgraph.tracing.transport.queue_connection' => 'realq',
        'agenticgraph.tracing.transport.queue_name' => 'pulse',
    ]);

    $job = new SendPulseSegmentJob('{}', 't', 's');

    expect($job->tries)->toBe(9)->and($job->connection)->toBe('realq')->and($job->queue)->toBe('pulse');
});

it('job: does not hold the api key or endpoint', function () {
    expect(serialize(new SendPulseSegmentJob('{}', 't', 's')))->not->toContain('test-key')->not->toContain('ingest.test');
});
