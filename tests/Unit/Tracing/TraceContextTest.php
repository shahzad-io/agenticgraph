<?php

use Shahzad\AgenticGraph\Tracing\TraceContext;

beforeEach(function () {
    TraceContext::reset();
});

it('starts and ends a trace', function () {
    expect(TraceContext::isEnabled())->toBeFalse();

    config()->set('agenticgraph.tracing.enabled', true);
    TraceContext::startTrace('exec_123');

    expect(TraceContext::isEnabled())->toBeTrue();
    expect(TraceContext::getExecutionId())->toBe('exec_123');

    TraceContext::endTrace();

    expect(TraceContext::isEnabled())->toBeFalse();
    expect(TraceContext::getExecutionId())->toBeNull();
});

it('manages span hierarchy correctly', function () {
    config()->set('agenticgraph.tracing.enabled', true);
    TraceContext::startTrace('exec_456');

    // Start first span (root)
    $span1 = TraceContext::startSpan('NodeA');
    expect($span1)->toStartWith('span_');
    expect(TraceContext::getSpanId())->toBe($span1);
    expect(TraceContext::getParentSpanId())->toBeNull();

    // Start nested span
    $span2 = TraceContext::startSpan('NodeB');
    expect(TraceContext::getSpanId())->toBe($span2);
    expect(TraceContext::getParentSpanId())->toBe($span1);

    // Start another nested span
    $span3 = TraceContext::startSpan('NodeC');
    expect(TraceContext::getSpanId())->toBe($span3);
    expect(TraceContext::getParentSpanId())->toBe($span2);

    // End span3, should return to span2
    TraceContext::endSpan();
    expect(TraceContext::getSpanId())->toBe($span2);

    // End span2, should return to span1
    TraceContext::endSpan();
    expect(TraceContext::getSpanId())->toBe($span1);

    // End span1, should return to null
    TraceContext::endSpan();
    expect(TraceContext::getSpanId())->toBeNull();
});

it('returns null when tracing is disabled', function () {
    config()->set('agenticgraph.tracing.enabled', false);
    TraceContext::startTrace('exec_789');

    expect(TraceContext::isEnabled())->toBeFalse();
    expect(TraceContext::startSpan('NodeA'))->toBeNull();
});

it('resets correctly', function () {
    config()->set('agenticgraph.tracing.enabled', true);
    TraceContext::startTrace('exec_000');
    TraceContext::startSpan('NodeA');

    TraceContext::reset();

    expect(TraceContext::isEnabled())->toBeFalse();
    expect(TraceContext::getExecutionId())->toBeNull();
    expect(TraceContext::getSpanId())->toBeNull();
});
