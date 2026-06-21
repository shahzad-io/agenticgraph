<?php

use Shahzad\AgenticGraph\Streaming\StreamOptions;

test('stream options serialize and unserialize', function () {
    $options = new StreamOptions(
        delivery: 'sse',
        interrupts: true,
        resumeFromCheckpointId: 'cp-1',
        resumeFromSeq: 5,
        storeEnabled: true,
        nodeFilter: ['node-a', 'node-b'],
        eventTypeFilter: ['text_delta']
    );

    $copy = unserialize(serialize($options));

    expect($copy)->toBeInstanceOf(StreamOptions::class)
        ->and($copy->delivery)->toBe('sse')
        ->and($copy->interrupts)->toBeTrue()
        ->and($copy->resumeFromCheckpointId)->toBe('cp-1')
        ->and($copy->resumeFromSeq)->toBe(5)
        ->and($copy->storeEnabled)->toBeTrue()
        ->and($copy->nodeFilter)->toBe(['node-a', 'node-b'])
        ->and($copy->eventTypeFilter)->toBe(['text_delta']);
});
