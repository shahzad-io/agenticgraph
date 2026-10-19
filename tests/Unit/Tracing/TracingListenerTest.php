<?php

namespace Shahzad\AgenticGraph\Tests\Unit\Tracing;

use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeInterrupted;
use Shahzad\AgenticGraph\Events\NodeResumed;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\Listeners\TracingListener;

it('calculates duration correctly mixed with queued delays', function (): void {
    $exporter = new MockPulseExporter;
    $listener = new TracingListener($exporter);

    // Simulate event created well before the listener runs
    $startedAt = new \DateTimeImmutable('2024-01-01 09:00:00.250000', new \DateTimeZone('UTC'));
    $duration = 5.000123; // seconds

    $event = new NodeExecutionCompleted(
        executionId: 'exec_123',
        nodeName: 'test-node',
        inputState: new State([]),
        outputState: new State([]),
        duration: $duration,
        metadata: [],
        spanId: 'span_1',
        startedAt: $startedAt,
        parentSpanId: 'parent_1'
    );

    // Artificially delay execution of listener (simulating queue)
    // In real world, this test runs instantly, but we check if it uses startedAt vs "now"

    $listener->onNodeCompleted($event);

    expect($exporter->spans)->toHaveCount(1);
    $span = $exporter->spans[0];

    expect($span['started_at'])->toBe('2024-01-01T09:00:00.250000+00:00');

    // End time should be start + duration, to the microsecond
    expect($span['ended_at'])->toBe('2024-01-01T09:00:05.250123+00:00');

    // Check parent ID
    expect($span['parent_id'])->toBe('parent_1');
});

it('records span attributes for node completion', function () {
    $exporter = new MockPulseExporter;
    $listener = new TracingListener($exporter);

    $startedAt = new \DateTimeImmutable('2024-01-01 09:00:00');
    $event = new NodeExecutionCompleted(
        executionId: 'exec_attr',
        nodeName: 'node-attr',
        inputState: new State(['a' => 1]),
        outputState: new State(['a' => 1, 'b' => 2]),
        duration: 0.25,
        metadata: ['node_type' => 'test-node'],
        spanId: 'span_attr',
        startedAt: $startedAt,
        parentSpanId: 'parent_attr'
    );

    $listener->onNodeCompleted($event);

    expect($exporter->spans)->toHaveCount(1);
    $span = $exporter->spans[0];

    expect($span['node_type'])->toBe('test-node')
        ->and($span['span_type'])->toBe('node')
        ->and($span['status'])->toBe('success')
        ->and($span['span_kind'])->toBe('internal')
        ->and($span['status_code'])->toBe('OK')
        ->and($span['state_diff'])->toHaveKey('added')
        ->and($span['state_diff'])->toHaveKey('removed')
        ->and($span['state_diff'])->toHaveKey('values')
        ->and($span['state_diff']['added'])->toBe(['b'])
        ->and($span['state_diff']['removed'])->toBe([]);
});

it('uses token event timestamps and ids', function () {
    $exporter = new MockPulseExporter;
    $listener = new TracingListener($exporter);

    $startedAt = new \DateTimeImmutable('2024-01-01 10:00:00.100000', new \DateTimeZone('UTC'));
    $durationMs = 1500.0;
    $endedAt = new \DateTimeImmutable('2024-01-01 10:00:01.600000', new \DateTimeZone('UTC'));

    $event = new TokensUsed(
        node: 'node-a',
        promptTokens: 10,
        completionTokens: 5,
        totalTokens: 15,
        executionId: 'exec_123',
        parentSpanId: 'parent_1',
        spanId: 'span_1',
        callType: 'text',
        model: 'openai:gpt-4',
        durationMs: $durationMs,
        startedAt: $startedAt,
        endedAt: $endedAt
    );

    $listener->onTokensUsed($event);

    expect($exporter->spans)->toHaveCount(1);
    $span = $exporter->spans[0];

    expect($span['trace_id'])->toBe('exec_123');
    expect($span['parent_id'])->toBe('parent_1');
    expect($span['id'])->toBe('span_1');
    expect($span['started_at'])->toBe('2024-01-01T10:00:00.100000+00:00');
    expect($span['ended_at'])->toBe('2024-01-01T10:00:01.600000+00:00');
});

it('starts trace with the event timestamp', function () {
    $exporter = new MockPulseExporter;
    $listener = new TracingListener($exporter);

    $startedAt = new \DateTimeImmutable('2024-02-01 08:30:00.654321', new \DateTimeZone('UTC'));
    $event = new GraphExecutionStarted(
        executionId: 'exec_999',
        graphName: 'graph-a',
        initialState: new State([]),
        startedAt: $startedAt,
        spanId: 'span_root'
    );

    $listener->onGraphStarted($event);

    expect($exporter->traces)->toHaveKey('exec_999');
    expect($exporter->traces['exec_999']['started_at'])->toBe('2024-02-01T08:30:00.654321+00:00');
});

it('marks llm spans and llm calls as streamed or not', function (bool $streamed) {
    $exporter = new MockPulseExporter;
    $listener = new TracingListener($exporter);

    $listener->onTokensUsed(new TokensUsed(
        node: 'node-a',
        promptTokens: 1,
        completionTokens: 1,
        totalTokens: 2,
        executionId: 'exec_streamed',
        parentSpanId: 'parent_1',
        spanId: 'span_1',
        callType: 'text',
        model: 'openai:gpt-4',
        durationMs: 1.0,
        startedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
        endedAt: new \DateTimeImmutable('2024-01-01 10:00:00.001'),
        streamed: $streamed,
    ));

    expect($exporter->spans[0]['streamed'])->toBe($streamed)
        ->and($exporter->llmCalls[0]['streamed'])->toBe($streamed);
})->with([true, false]);

it('adds thought_tokens to the llm span only when the provider reported them', function (?int $thoughtTokens) {
    $exporter = new MockPulseExporter;
    (new TracingListener($exporter))->onTokensUsed(new TokensUsed(
        node: 'node-a',
        promptTokens: 1,
        completionTokens: 1,
        totalTokens: 2,
        executionId: 'exec_thought',
        spanId: 'span_t',
        model: 'openai:o3',
        durationMs: 1.0,
        startedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
        endedAt: new \DateTimeImmutable('2024-01-01 10:00:00.001'),
        thoughtTokens: $thoughtTokens,
    ));

    expect(array_key_exists('thought_tokens', $exporter->spans[0]))->toBe($thoughtTokens !== null)
        ->and($exporter->spans[0]['thought_tokens'] ?? null)->toBe($thoughtTokens);
})->with([0, 12, null]);

it('puts interrupt and resume fields in attributes, keeping state_snapshot nested', function (): void {
    $exporter = new MockPulseExporter;
    $listener = new TracingListener($exporter);
    $at = new \DateTimeImmutable('2026-09-27 10:00:00.000000', new \DateTimeZone('UTC'));

    $listener->onNodeInterrupted(new NodeInterrupted('exec_int', 'approve', 'Needs approval', 'cp_1', ['amount' => 5], $at, parentSpanId: 'span_root'));
    $listener->onNodeResumed(new NodeResumed('exec_int', 'approve', 'cp_1', ['amount' => 5], $at, 'span_root'));

    [$interrupt, $resume] = $exporter->spans;

    expect($interrupt['attributes'])->toBe(['interrupt.reason' => 'Needs approval', 'checkpoint.id' => 'cp_1'])
        ->and($interrupt['state_snapshot'])->toBe(['amount'])
        ->and($resume['attributes'])->toBe(['checkpoint.id' => 'cp_1'])
        ->and($resume['state_snapshot'])->toBe(['amount'])
        ->and($interrupt)->not->toHaveKeys(['interrupt_reason', 'checkpoint_id'])
        ->and($resume)->not->toHaveKey('checkpoint_id');
});

it('adds cache_read_tokens and cache_write_tokens to the llm span only when reported, and prices them', function (?int $read, ?int $write) {
    config()->set('model_pricing.models', ['cachey/model' => ['input' => 2.00, 'output' => 8.00, 'cache_read' => 0.50]]);
    $exporter = new MockPulseExporter;
    (new TracingListener($exporter))->onTokensUsed(new TokensUsed(
        node: 'node-a',
        promptTokens: 1_000_000,
        completionTokens: 0,
        totalTokens: 1_000_000,
        executionId: 'exec_cache',
        spanId: 'span_c',
        model: 'cachey:model',
        durationMs: 1.0,
        startedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
        endedAt: new \DateTimeImmutable('2024-01-01 10:00:00.001'),
        cacheReadTokens: $read,
        cacheWriteTokens: $write,
    ));

    $span = $exporter->spans[0];
    expect(array_key_exists('cache_read_tokens', $span))->toBe($read !== null)
        ->and($span['cache_read_tokens'] ?? null)->toBe($read)
        ->and(array_key_exists('cache_write_tokens', $span))->toBe($write !== null)
        ->and($span['cache_write_tokens'] ?? null)->toBe($write)
        ->and($span['total_tokens'])->toBe(1_000_000)
        ->and($span['cost_usd'])->toBe(round(2.0 + ($read ?? 0) / 1e6 * 0.5 + ($write ?? 0) / 1e6 * 2.0, 6));
})->with([[1_000_000, 500_000], [0, null], [null, null]]);
