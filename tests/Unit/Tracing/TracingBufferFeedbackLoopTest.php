<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\Instrumentation\DatabaseInstrumentation;
use Shahzad\AgenticGraph\Tracing\Listeners\TracingListener;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;

function enableTracingForBufferTests(): PulseExporter
{
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'pulse',
        'agenticgraph.tracing.api_key' => 'pk_test_buffer',
        'agenticgraph.tracing.endpoint' => 'https://api.example.com',
    ]);

    return new PulseExporter;
}

function registerDatabaseCacheForBufferTests(): void
{
    if (! Schema::hasTable('cache')) {
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
    }

    config([
        'cache.default' => 'database',
        'cache.stores.database' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'cache',
        ],
    ]);

    Cache::flush();
}

function registerDatabaseInstrumentation(TracerExporter $exporter): DatabaseInstrumentation
{
    $instrumentation = new DatabaseInstrumentation($exporter, 1000.0);
    $instrumentation->register(app('events'));

    return $instrumentation;
}

function bindTraceContext(string $executionId = 'exec-buffer-test'): TraceContext
{
    $traceContext = new TraceContext($executionId, 'span_root');
    app()->instance(TraceContext::class, $traceContext);

    return $traceContext;
}

function makeQueryExecutedEvent(string $sql): QueryExecuted
{
    return new QueryExecuted(
        $sql,
        [],
        1.5,
        DB::connection('testing')
    );
}

function spanPayload(string $traceId, string $id, string $spanType = 'node'): array
{
    return [
        'id' => $id,
        'trace_id' => $traceId,
        'node_name' => 'node-'.$id,
        'span_type' => $spanType,
        'status' => 'success',
        'started_at' => now()->toIso8601String(),
        'ended_at' => now()->toIso8601String(),
        'duration_ms' => 1.0,
    ];
}

it('does not enter a db span feedback loop when the application cache uses the database driver', function () {
    registerDatabaseCacheForBufferTests();
    $exporter = enableTracingForBufferTests();
    registerDatabaseInstrumentation($exporter);
    bindTraceContext('exec-feed');

    $exporter->startTrace('exec-feed', 'feedback-graph');

    for ($i = 0; $i < 30; $i++) {
        $exporter->addSpan(spanPayload('exec-feed', "span_{$i}"));
    }

    $buffer = $exporter->getBuffer();
    $dbSpans = array_values(array_filter(
        $buffer['spans'],
        fn (array $span): bool => ($span['span_type'] ?? '') === 'db'
    ));

    expect($buffer['spans'])->toHaveCount(30)
        ->and($dbSpans)->toBeEmpty();
});

it('keeps memory bounded when accumulating many spans before a single flush', function () {
    registerDatabaseCacheForBufferTests();
    Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

    $exporter = enableTracingForBufferTests();
    registerDatabaseInstrumentation($exporter);
    bindTraceContext('exec-mem');

    $exporter->startTrace('exec-mem', 'memory-graph');
    $memoryBefore = memory_get_usage(true);

    for ($i = 0; $i < 100; $i++) {
        $exporter->addSpan(spanPayload('exec-mem', "span_{$i}"));
        $exporter->addLlmCall([
            'id' => "llm_{$i}",
            'trace_id' => 'exec-mem',
            'node' => 'generate',
            'call_type' => 'text',
            'model' => 'openai:gpt-4o',
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'duration_ms' => 5.0,
        ]);
    }

    $memoryAfterCapture = memory_get_usage(true);
    $growthMb = ($memoryAfterCapture - $memoryBefore) / 1024 / 1024;

    expect($exporter->getBuffer()['spans'])->toHaveCount(100)
        ->and($exporter->getBuffer()['llm_calls'])->toHaveCount(100)
        ->and($growthMb)->toBeLessThan(5);

    app()->forgetInstance('request');
    $exporter->completeTrace('exec-mem', ['result'], 250.0, ['result' => 'ok']);

    Http::assertSentCount(1);
});

it('does not capture database queries while the exporter is capturing', function () {
    $exporter = enableTracingForBufferTests();
    bindTraceContext('exec-guard');

    $ref = new ReflectionClass(PulseExporter::class);
    $property = $ref->getProperty('captureDepth');
    $property->setAccessible(true);
    $property->setValue($exporter, 1);

    $instrumentation = new DatabaseInstrumentation($exporter, 1000.0);

    $instrumentation->handleQuery(makeQueryExecutedEvent('select * from `cache` where `key` in (?)'));
    $instrumentation->handleQuery(makeQueryExecutedEvent('select * from "cache" where "key" in (?)'));

    expect($exporter->getBuffer()['spans'])->toBeEmpty();

    $property->setValue($exporter, 0);
    $instrumentation->handleQuery(makeQueryExecutedEvent('select * from users where id = ?'));

    expect($exporter->getBuffer()['spans'])->toHaveCount(1)
        ->and($exporter->getBuffer()['spans'][0]['span_type'])->toBe('db');
});

it('flushes only once when completeTrace is called twice', function () {
    Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

    app()->forgetInstance('request');

    $exporter = enableTracingForBufferTests();
    $exporter->startTrace('exec-double', 'double-graph');
    $exporter->addSpan(spanPayload('exec-double', 'span_1'));

    $exporter->completeTrace('exec-double', ['done'], 100.0, ['done' => true]);
    $exporter->completeTrace('exec-double', ['done'], 100.0, ['done' => true]);

    Http::assertSentCount(1);
});

it('still captures graph node and llm spans through the listener stack', function () {
    Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'pulse',
        'agenticgraph.tracing.api_key' => 'pk_test_listener',
        'agenticgraph.tracing.endpoint' => 'https://api.example.com',
        'agenticgraph.tracing.capture.state' => 'diff',
        'agenticgraph.tracing.capture.prompts' => true,
        'agenticgraph.tracing.capture.responses' => true,
    ]);

    app()->forgetInstance('request');

    $exporter = app(TracerExporter::class);
    $listener = new TracingListener($exporter);

    $executionId = 'exec-listener';
    app()->instance(TraceContext::class, new TraceContext($executionId, 'span_root'));

    $listener->onGraphStarted(new GraphExecutionStarted(
        $executionId,
        'demo-graph',
        new State(['input' => 'hello']),
        new DateTimeImmutable
    ));

    $listener->onNodeCompleted(new NodeExecutionCompleted(
        executionId: $executionId,
        nodeName: 'generate',
        inputState: new State(['input' => 'hello']),
        outputState: new State(['input' => 'hello', 'draft' => 'text']),
        duration: 0.5,
        metadata: ['node_type' => 'prism'],
        spanId: 'span_node',
    ));

    $listener->onTokensUsed(new TokensUsed(
        node: 'generate',
        promptTokens: 12,
        completionTokens: 34,
        totalTokens: 46,
        executionId: $executionId,
        spanId: 'span_llm',
        callType: 'text',
        model: 'openai:gpt-4o',
        durationMs: 120.0,
        prompt: 'Write hello',
        response: 'Hello world',
    ));

    $listener->onGraphCompleted(new GraphExecutionCompleted(
        $executionId,
        'demo-graph',
        new State(['input' => 'hello', 'draft' => 'text']),
        new DateTimeImmutable,
        1.25,
        [],
        'span_root'
    ));

    Http::assertSent(function ($request) {
        $payload = $request->data();
        $spanTypes = array_map(fn (array $span): string => (string) ($span['span_type'] ?? ''), $payload['spans'] ?? []);
        $llmCalls = $payload['llm_calls'] ?? [];

        return $request->url() === 'https://api.example.com/v1/ingest'
            && in_array('node', $spanTypes, true)
            && in_array('graph', $spanTypes, true)
            && in_array('llm', $spanTypes, true)
            && count($llmCalls) === 1
            && ($llmCalls[0]['prompt'] ?? null) === 'Write hello'
            && ($llmCalls[0]['response'] ?? null) === 'Hello world';
    });
});
