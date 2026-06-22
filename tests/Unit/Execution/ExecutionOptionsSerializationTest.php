<?php

use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;

it('round-trips execution options through canonical array form', function () {
    $original = new ExecutionOptions(
        mode: ExecutionMode::ASYNC,
        streaming: StreamingMode::BROADCAST,
        interrupts: false,
        estimatedDurationSeconds: 42
    );

    $array = $original->toArray();
    $restored = ExecutionOptions::fromArray($array);

    expect($array)->toBe([
        'mode' => 'async',
        'streaming' => 'broadcast',
        'interrupts' => false,
        'estimated_duration_seconds' => 42,
    ])
        ->and($restored)->toEqual($original);
});

it('defaults interrupts to true when missing from stored array', function () {
    $restored = ExecutionOptions::fromArray([
        'mode' => 'sync',
        'streaming' => 'none',
    ]);

    expect($restored->interrupts)->toBeTrue()
        ->and($restored->estimatedDurationSeconds)->toBeNull();
});
