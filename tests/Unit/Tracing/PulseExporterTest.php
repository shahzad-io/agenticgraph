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

it('sends each segment with only its own spans across interrupt and resume', function () {
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

    $exporter->resumeTrace($traceId, 'refund');

    $buffer = $exporter->getBuffer();
    expect($buffer['traces'][$traceId]['status'] ?? null)->toBe('running')
        ->and($buffer['spans'])->toBe([]);

    $exporter->addSpan([
        'id' => 'span_after',
        'trace_id' => $traceId,
        'span_type' => 'resume',
        'node_name' => 'await_approval (resumed)',
    ]);

    $exporter->completeTrace($traceId, ['final_decision'], 1500.0);

    expect($exporter->getBuffer()['traces'])->toBeEmpty();

    $sent = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->values()->all();
    expect($sent)->toHaveCount(2)
        ->and($sent[0]['segment']['kind'])->toBe('initial')
        ->and($sent[0]['segment']['final'])->toBeFalse()
        ->and(array_column($sent[0]['spans'], 'id'))->toBe(['span_before'])
        ->and($sent[1]['segment']['kind'])->toBe('resumed')
        ->and($sent[1]['segment']['final'])->toBeTrue()
        ->and($sent[1]['trace']['status'])->toBe('completed')
        ->and(array_column($sent[1]['spans'], 'id'))->toBe(['span_after']);
});
