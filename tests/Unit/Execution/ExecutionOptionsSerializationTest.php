<?php

use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Streaming\StreamLifecycleEventTypes;

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
        'node_filter' => null,
        'event_type_filter' => null,
    ])
        ->and($restored)->toEqual($original);
});

it('round-trips node and event type filters through canonical array form', function () {
    $original = new ExecutionOptions(
        mode: ExecutionMode::ASYNC,
        streaming: StreamingMode::SSE,
        nodeFilter: ['alpha', 'gamma'],
        eventTypeFilter: ['text_delta', StreamLifecycleEventTypes::NODE_COMPLETE],
    );

    $array = $original->toArray();
    $restored = ExecutionOptions::fromArray($array);
    $stored = ExecutionOptions::fromStored(json_encode($array, JSON_THROW_ON_ERROR));

    expect($array)->toBe([
        'mode' => 'async',
        'streaming' => 'sse',
        'interrupts' => true,
        'estimated_duration_seconds' => null,
        'node_filter' => ['alpha', 'gamma'],
        'event_type_filter' => ['text_delta', StreamLifecycleEventTypes::NODE_COMPLETE],
    ])
        ->and($restored)->toEqual($original)
        ->and($stored)->toEqual($original);
});

it('defaults interrupts to true when missing from stored array', function () {
    $restored = ExecutionOptions::fromArray([
        'mode' => 'sync',
        'streaming' => 'none',
    ]);

    expect($restored->interrupts)->toBeTrue()
        ->and($restored->estimatedDurationSeconds)->toBeNull()
        ->and($restored->nodeFilter)->toBeNull()
        ->and($restored->eventTypeFilter)->toBeNull();
});

it('deserializes legacy stored rows without filter keys to null filters', function () {
    $legacyJson = json_encode([
        'mode' => 'async',
        'streaming' => 'sse',
        'interrupts' => true,
        'estimated_duration_seconds' => null,
    ], JSON_THROW_ON_ERROR);

    $restored = ExecutionOptions::fromStored($legacyJson);

    expect($restored->nodeFilter)->toBeNull()
        ->and($restored->eventTypeFilter)->toBeNull()
        ->and($restored->mode)->toBe(ExecutionMode::ASYNC)
        ->and($restored->streaming)->toBe(StreamingMode::SSE);
});

it('returns constructor defaults for null or empty stored json', function () {
    $defaults = new ExecutionOptions;

    expect(ExecutionOptions::fromStored(null))->toEqual($defaults)
        ->and(ExecutionOptions::fromStored(''))->toEqual($defaults)
        ->and(ExecutionOptions::fromStored('not-json'))->toEqual($defaults)
        ->and(ExecutionOptions::fromStored('{}'))->toEqual($defaults);
});
