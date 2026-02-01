<?php

use Shahzad\AgenticGraph\Tracing\TraceContext;

beforeEach(function () {
    // Clear any existing TraceContext instance from container
    app()->forgetInstance(TraceContext::class);
});

it('creates a trace context with execution ID', function () {
    config()->set('agenticgraph.tracing.enabled', true);

    $traceContext = new TraceContext(
        executionId: 'exec_123',
        rootSpanId: 'span_root'
    );

    expect($traceContext->isEnabled())->toBeTrue();
    expect($traceContext->getExecutionId())->toBe('exec_123');
    expect($traceContext->getRootSpanId())->toBe('span_root');
});

it('manages span hierarchy correctly', function () {
    config()->set('agenticgraph.tracing.enabled', true);

    $traceContext = new TraceContext(
        executionId: 'exec_456',
        rootSpanId: 'span_root'
    );

    $rootSpan = $traceContext->getRootSpanId();
    expect($rootSpan)->toBe('span_root');

    // Start first span
    $span1 = $traceContext->startSpan('NodeA');
    expect($span1)->toStartWith('span_');
    expect($traceContext->getSpanId())->toBe($span1);
    expect($traceContext->getParentSpanId())->toBe($rootSpan);

    // Start nested span
    $span2 = $traceContext->startSpan('NodeB');
    expect($traceContext->getSpanId())->toBe($span2);
    expect($traceContext->getParentSpanId())->toBe($span1);

    // Start another nested span
    $span3 = $traceContext->startSpan('NodeC');
    expect($traceContext->getSpanId())->toBe($span3);
    expect($traceContext->getParentSpanId())->toBe($span2);

    // End span3, should return to span2
    $traceContext->endSpan();
    expect($traceContext->getSpanId())->toBe($span2);

    // End span2, should return to span1
    $traceContext->endSpan();
    expect($traceContext->getSpanId())->toBe($span1);

    // End span1, should return to root
    $traceContext->endSpan();
    expect($traceContext->getSpanId())->toBe($rootSpan);

    // End root span
    $traceContext->endSpan();
    expect($traceContext->getSpanId())->toBeNull();
});

it('returns null when tracing is disabled', function () {
    config()->set('agenticgraph.tracing.enabled', false);

    $traceContext = new TraceContext(
        executionId: 'exec_789',
        rootSpanId: 'span_root'
    );

    expect($traceContext->isEnabled())->toBeFalse();
    expect($traceContext->startSpan('NodeA'))->toBeNull();
});

it('isolates trace contexts between instances', function () {
    config()->set('agenticgraph.tracing.enabled', true);

    $context1 = new TraceContext(
        executionId: 'exec_001',
        rootSpanId: 'span_root_1'
    );

    $context2 = new TraceContext(
        executionId: 'exec_002',
        rootSpanId: 'span_root_2'
    );

    // Each context maintains its own state
    expect($context1->getExecutionId())->toBe('exec_001');
    expect($context2->getExecutionId())->toBe('exec_002');

    $span1 = $context1->startSpan('NodeA');
    $span2 = $context2->startSpan('NodeB');

    expect($context1->getSpanId())->toBe($span1);
    expect($context2->getSpanId())->toBe($span2);
    expect($span1)->not->toBe($span2);
});
