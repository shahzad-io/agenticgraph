<?php

namespace Shahzad\AgenticGraph\Tests\Unit\Tracing;

use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\Listeners\TracingListener;
use Shahzad\AgenticGraph\Tracing\PulseExporter;

// Mock PulseExporter
class MockPulseExporter extends PulseExporter
{
    public array $spans = [];

    public array $llmCalls = [];

    public array $traces = [];

    public bool $enabled = true;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function addSpan(array $span): void
    {
        $this->spans[] = $span;
    }

    public function addLlmCall(array $call): void
    {
        $this->llmCalls[] = $call;
    }

    public function startTrace(
        string $traceId,
        string $graphName,
        array $initialStateKeys = [],
        ?\DateTimeImmutable $startedAt = null,
        ?array $initialStateData = null
    ): void {
        $this->traces[$traceId] = [
            'graph_name' => $graphName,
            'initial_state_keys' => $initialStateKeys,
            'started_at' => $startedAt?->format(DATE_ATOM),
            'initial_state' => $initialStateData,
        ];
    }
}

it('calculates duration correctly mixed with queued delays', function () {
    $exporter = new MockPulseExporter;
    $listener = new TracingListener($exporter);

    // Simulate event created 1 hour ago
    $startedAt = new \DateTimeImmutable('-1 hour');
    $duration = 5.0; // 5 seconds

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

    expect($span['started_at'])->toBe($startedAt->format(DATE_ATOM));

    // End time should be start + duration
    $expectedEnd = $startedAt->modify('+5 seconds')->format(DATE_ATOM);
    expect($span['ended_at'])->toBe($expectedEnd);

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
        ->and($span['state_diff'])->toHaveKey('values');
});

it('uses token event timestamps and ids', function () {
    $exporter = new MockPulseExporter;
    $listener = new TracingListener($exporter);

    $startedAt = new \DateTimeImmutable('2024-01-01 10:00:00');
    $durationMs = 1500.0;
    // Use DateInterval to properly add 1.5 seconds (modify() has a bug with fractional seconds)
    $interval = new \DateInterval('PT1S');
    $interval->f = 0.5;
    $endedAt = $startedAt->add($interval);

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
    expect($span['started_at'])->toBe($startedAt->format(DATE_ATOM));
    expect($span['ended_at'])->toBe($endedAt->format(DATE_ATOM));
});

it('starts trace with the event timestamp', function () {
    $exporter = new MockPulseExporter;
    $listener = new TracingListener($exporter);

    $startedAt = new \DateTimeImmutable('2024-02-01 08:30:00');
    $event = new GraphExecutionStarted(
        executionId: 'exec_999',
        graphName: 'graph-a',
        initialState: new State([]),
        startedAt: $startedAt,
        spanId: 'span_root'
    );

    $listener->onGraphStarted($event);

    expect($exporter->traces)->toHaveKey('exec_999');
    expect($exporter->traces['exec_999']['started_at'])->toBe($startedAt->format(DATE_ATOM));
});
