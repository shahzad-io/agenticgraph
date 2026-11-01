<?php

declare(strict_types=1);

use Shahzad\AgenticGraph\Tracing\CaptureDepth;
use Shahzad\AgenticGraph\Tracing\Listeners\TracingListener;

it('the minimum covers the longest marker the package can write', function () {
    $longest = strlen(' [...truncated '.PHP_INT_MAX.']');

    expect(CaptureDepth::MIN_MAX_LENGTH)->toBeGreaterThan($longest);
});

it('rejects a max_length below the minimum, naming key, value and minimum', function () {
    config(['agenticgraph.tracing.capture.max_length' => 10]);

    expect(fn () => CaptureDepth::truncate(str_repeat('x', 100)))
        ->toThrow(InvalidArgumentException::class, 'agenticgraph.tracing.capture.max_length')
        ->and(fn () => CaptureDepth::truncate('x'))->toThrow(InvalidArgumentException::class, '10')
        ->and(fn () => CaptureDepth::truncate('x'))->toThrow(InvalidArgumentException::class, (string) CaptureDepth::MIN_MAX_LENGTH);
});

it('rejects it when the listener is built, not mid-run', function () {
    config(['agenticgraph.tracing.capture.max_length' => CaptureDepth::MIN_MAX_LENGTH - 1]);

    expect(fn () => new TracingListener)->toThrow(InvalidArgumentException::class, 'agenticgraph.tracing.capture.max_length');
});

it('accepts the minimum and cuts to exactly max_length', function () {
    config(['agenticgraph.tracing.capture.max_length' => CaptureDepth::MIN_MAX_LENGTH]);

    $cut = CaptureDepth::truncate(str_repeat('x', 100_000));

    expect(strlen($cut))->toBe(CaptureDepth::MIN_MAX_LENGTH)
        ->and($cut)->toEndWith(' [...truncated 100000]')
        ->and(new TracingListener)->toBeInstanceOf(TracingListener::class);
});
