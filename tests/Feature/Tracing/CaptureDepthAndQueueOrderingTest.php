<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeInterrupted;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\ExecutionContextManager;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionScope;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\CaptureDepth;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\Listeners\TracingListener;
use Shahzad\AgenticGraph\Tracing\TraceContext;

final class QueuedTracingRecordingExporter implements TracerExporter
{
    /** @var array<int, array<string, mixed>> */
    public array $spans = [];

    public int $completeTraceCalls = 0;

    public function isEnabled(): bool
    {
        return true;
    }

    public function isCapturing(): bool
    {
        return false;
    }

    public function getTraceStartTime(string $traceId): ?Carbon
    {
        return null;
    }

    public function startTrace(
        string $traceId,
        string $graphName,
        array $initialStateKeys = [],
        ?DateTimeImmutable $startedAt = null,
        ?array $initialStateData = null
    ): void {}

    public function addSpan(array $span): void
    {
        $this->spans[] = $span;
    }

    public function addLlmCall(array $call): void {}

    public function completeTrace(
        string $traceId,
        array $finalStateKeys = [],
        float $durationMs = 0,
        ?array $finalStateData = null,
        ?DateTimeInterface $at = null
    ): void {
        $this->completeTraceCalls++;
    }

    public function failTrace(
        string $traceId,
        Throwable $exception,
        float $durationMs = 0,
        ?array $finalStateData = null,
        ?DateTimeInterface $at = null
    ): void {}

    public function interruptTrace(string $traceId, string $reason, ?DateTimeInterface $at = null): void {}

    public function resumeTrace(string $traceId, ?string $graphName = null, string $kind = 'resumed', ?DateTimeInterface $at = null): void {}

    public function flush(string $traceId): void {}
}

function registerJobsTableForQueuedTracingTests(): void
{
    if (! Schema::hasTable('jobs')) {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }
}

it('does not carry prompt or response in TokensUsed when capture is disabled', function (): void {
    config([
        'agenticgraph.tracing.capture.prompts' => false,
        'agenticgraph.tracing.capture.responses' => false,
    ]);

    $event = TokensUsed::fromLlmCall(
        node: 'generate',
        callStartMicrotime: microtime(true),
        callDurationMs: 10.0,
        promptTokens: 5,
        completionTokens: 7,
        callType: 'text',
        model: 'openai:gpt-4o',
        prompt: 'secret prompt',
        response: 'secret response',
    );

    expect($event->prompt)->toBeNull()
        ->and($event->response)->toBeNull()
        ->and($event->promptTokens)->toBe(5)
        ->and($event->completionTokens)->toBe(7);
});

it('redacts http url and db sql by default capture depth', function (): void {
    config([
        'agenticgraph.tracing.capture.http_urls' => 'summary',
        'agenticgraph.tracing.capture.db_sql' => 'summary',
    ]);

    $http = CaptureDepth::httpRequestFields('GET', 'https://api.example.com/rates?apiKey=secret');
    $sql = CaptureDepth::dbSqlForSpan('select * from `users` where email = ?');

    expect($http)->not->toHaveKey('url.full')
        ->and($http['laravel.http.url_summary'])->toBe('https://api.example.com/rates')
        ->and($sql)->toBe(['laravel.db.query_summary' => 'SELECT users']);
});

it('summarizes associative prism tool args by parameter name', function (): void {
    config(['agenticgraph.tracing.capture.tool_args' => 'summary']);

    $fields = CaptureDepth::toolArgsForSpan(['city' => 'Reykjavik']);

    expect($fields)->toBe(['tool.arg_keys' => ['city']]);
});

it('execution exit does not call completeTrace before queued listeners flush', function (): void {
    $exporter = new QueuedTracingRecordingExporter;
    app()->instance(TracerExporter::class, $exporter);

    $executionId = 'exec-exit-guard';
    $scope = new ExecutionScope(
        executionId: $executionId,
        traceContext: new TraceContext($executionId, 'span_root'),
        clock: new ExecutionClock($executionId),
        context: new ExecutionContext($executionId, microtime(true)),
        startTime: microtime(true),
        bindings: new stdClass,
        options: new ExecutionOptions(ExecutionMode::SYNC, StreamingMode::NONE),
    );

    app(ExecutionContextManager::class)->exit($scope, new State(['result' => 'ok']));

    expect($exporter->completeTraceCalls)->toBe(0)
        ->and($exporter->spans)->toBeEmpty();
});

it('delivers graph node and llm spans when tracing listeners run before graph completion', function (): void {
    $exporter = new QueuedTracingRecordingExporter;
    $listener = new TracingListener($exporter);

    $executionId = 'exec-listener-order';
    $listener->onGraphStarted(new GraphExecutionStarted(
        $executionId,
        'order-graph',
        new State(['input' => 'x']),
        new DateTimeImmutable
    ));
    $listener->onNodeCompleted(new NodeExecutionCompleted(
        executionId: $executionId,
        nodeName: 'generate',
        inputState: new State(['input' => 'x']),
        outputState: new State(['input' => 'x', 'out' => 'y']),
        duration: 0.2,
        metadata: ['node_type' => 'prism'],
        spanId: 'span_node',
        parentSpanId: 'span_root',
    ));
    $listener->onTokensUsed(new TokensUsed(
        node: 'generate',
        promptTokens: 10,
        completionTokens: 20,
        totalTokens: 30,
        executionId: $executionId,
        parentSpanId: 'span_node',
        spanId: 'span_llm',
        callType: 'text',
        model: 'openai:gpt-4o',
        durationMs: 50.0,
    ));
    $listener->onGraphCompleted(new GraphExecutionCompleted(
        $executionId,
        'order-graph',
        new State(['input' => 'x', 'out' => 'y']),
        new DateTimeImmutable,
        0.5,
        [],
        'span_root'
    ));

    $spanTypes = array_map(
        fn (array $span): string => (string) ($span['span_type'] ?? ''),
        $exporter->spans
    );

    expect($spanTypes)->toContain('node')
        ->and($spanTypes)->toContain('llm')
        ->and($spanTypes)->toContain('graph')
        ->and($exporter->completeTraceCalls)->toBe(1);
});

it('nests interrupt spans under the interrupted node span', function (): void {
    $exporter = new QueuedTracingRecordingExporter;
    $listener = new TracingListener($exporter);

    $listener->onNodeInterrupted(new NodeInterrupted(
        executionId: 'exec-interrupt',
        nodeName: 'approve',
        reason: 'human_input',
        checkpointId: 'ckpt_1',
        stateSnapshot: ['draft' => 'text'],
        interruptedAt: new DateTimeImmutable,
        parentSpanId: 'span_node',
    ));

    expect($exporter->spans[0]['parent_id'] ?? null)->toBe('span_node')
        ->and($exporter->spans[0]['span_type'] ?? null)->toBe('interrupt');
});
