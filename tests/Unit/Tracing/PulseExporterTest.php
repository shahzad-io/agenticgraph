<?php

use Illuminate\Support\Facades\Http;
use Shahzad\AgenticGraph\Tracing\PulseExporter;

beforeEach(function () {
    config()->set('agenticgraph.tracing.enabled', false);
    config()->set('agenticgraph.tracing.api_key', null);
});

it('is disabled when tracing is off', function () {
    $exporter = new PulseExporter;
    expect($exporter->isEnabled())->toBeFalse();
});

it('is disabled when api key is missing', function () {
    config()->set('agenticgraph.tracing.enabled', true);
    config()->set('agenticgraph.tracing.api_key', null);

    $exporter = new PulseExporter;
    expect($exporter->isEnabled())->toBeFalse();
});

it('is enabled when configured correctly', function () {
    config()->set('agenticgraph.tracing.enabled', true);
    config()->set('agenticgraph.tracing.api_key', 'pk_test_xxx');
    config()->set('agenticgraph.tracing.endpoint', 'https://api.test.io');

    $exporter = new PulseExporter;
    expect($exporter->isEnabled())->toBeTrue();
});

it('buffers traces and spans', function () {
    config()->set('agenticgraph.tracing.enabled', true);
    config()->set('agenticgraph.tracing.api_key', 'pk_test_xxx');

    $exporter = new PulseExporter;

    $exporter->startTrace('exec_123', 'test-graph', ['input']);
    $exporter->addSpan([
        'id' => 'span_1',
        'trace_id' => 'exec_123',
        'node_name' => 'NodeA',
    ]);
    $exporter->addLlmCall([
        'id' => 'llm_1',
        'trace_id' => 'exec_123',
        'tokens' => 100,
    ]);

    $buffer = $exporter->getBuffer();

    expect($buffer['traces'])->toHaveKey('exec_123');
    expect($buffer['spans'])->toHaveCount(1);
    expect($buffer['llm_calls'])->toHaveCount(1);
});

it('resets buffer after flush', function () {
    config()->set('agenticgraph.tracing.enabled', true);
    config()->set('agenticgraph.tracing.api_key', 'pk_test_xxx');

    $exporter = new PulseExporter;

    $exporter->startTrace('exec_456', 'test-graph');
    $exporter->addSpan(['id' => 'span_1', 'trace_id' => 'exec_456']);

    $exporter->reset();

    $buffer = $exporter->getBuffer();
    expect($buffer['traces'])->toBeEmpty();
    expect($buffer['spans'])->toBeEmpty();
});

it('completes trace with correct status', function () {
    config()->set('agenticgraph.tracing.enabled', true);
    config()->set('agenticgraph.tracing.api_key', 'pk_test_xxx');

    $exporter = new PulseExporter;

    $exporter->startTrace('exec_789', 'test-graph');

    $buffer = $exporter->getBuffer();
    expect($buffer['traces']['exec_789']['status'])->toBe('running');
});

it('flags spans outside the trace window', function () {
    $exporter = new class extends PulseExporter
    {
        public function findOutside(array $trace, array $spans): array
        {
            return $this->findSpansOutsideTraceWindow($trace, $spans);
        }
    };

    $traceStart = new DateTimeImmutable('2026-01-31T22:00:53+00:00');
    $trace = [
        'started_at' => $traceStart->format(DATE_ATOM),
        'ended_at' => $traceStart->modify('+7 seconds')->format(DATE_ATOM),
        'duration_ms' => 7000,
    ];

    $spans = [
        [
            'id' => 'span_ok',
            'started_at' => $traceStart->format(DATE_ATOM),
            'ended_at' => $traceStart->modify('+1 seconds')->format(DATE_ATOM),
        ],
        [
            'id' => 'span_bad',
            'started_at' => $traceStart->modify('+2 days')->format(DATE_ATOM),
            'ended_at' => $traceStart->modify('+2 days +1 seconds')->format(DATE_ATOM),
        ],
    ];

    $invalid = $exporter->findOutside($trace, $spans);

    expect($invalid)->toHaveCount(1);
    expect($invalid[0]['id'])->toBe('span_bad');
});

it('persists and restores interrupted trace buffers across resume', function () {
    config()->set('agenticgraph.tracing.enabled', true);
    config()->set('agenticgraph.tracing.api_key', 'pk_test_xxx');
    config()->set('agenticgraph.tracing.endpoint', 'https://api.test.io');

    Http::fake(['*' => Http::response(['status' => 'captured'], 202)]);

    $exporter = new PulseExporter;
    $traceId = 'exec_interrupt_resume';

    $exporter->startTrace($traceId, 'refund', ['email']);
    $exporter->addSpan([
        'id' => 'span_before',
        'trace_id' => $traceId,
        'span_type' => 'node',
        'node_name' => 'assess_refund',
    ]);

    $exporter->interruptTrace($traceId, 'human approval required');

    expect($exporter->getBuffer()['traces'])->toBeEmpty();

    $exporter->resumeTrace($traceId);

    $buffer = $exporter->getBuffer();
    expect($buffer['traces'][$traceId]['status'] ?? null)->toBe('running')
        ->and($buffer['spans'])->toHaveCount(1)
        ->and($buffer['spans'][0]['id'])->toBe('span_before');

    $exporter->addSpan([
        'id' => 'span_after',
        'trace_id' => $traceId,
        'span_type' => 'resume',
        'node_name' => 'await_approval (resumed)',
    ]);

    $exporter->completeTrace($traceId, ['final_decision'], 1500.0);

    $buffer = $exporter->getBuffer();
    expect($buffer['traces'])->toBeEmpty();
});
