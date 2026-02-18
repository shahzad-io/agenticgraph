<?php

use Shahzad\AgenticGraph\Streaming\StreamOptions;

test('stream options serialize and unserialize', function () {
    $options = new StreamOptions(
        delivery: 'sse',
        mode: 'all_nodes',
        interrupts: true,
        resume: true,
        resumeFromCheckpointId: 'cp-1',
        resumeFromSeq: 5,
        heartbeatInterval: 15,
        storeEnabled: true,
        streamPartialState: false,
        nodeFilter: ['node-a', 'node-b'],
        eventTypeFilter: ['text_delta']
    );

    $copy = unserialize(serialize($options));

    expect($copy)->toBeInstanceOf(StreamOptions::class)
        ->and($copy->delivery)->toBe('sse')
        ->and($copy->mode)->toBe('all_nodes')
        ->and($copy->interrupts)->toBeTrue()
        ->and($copy->resume)->toBeTrue()
        ->and($copy->resumeFromCheckpointId)->toBe('cp-1')
        ->and($copy->resumeFromSeq)->toBe(5)
        ->and($copy->heartbeatInterval)->toBe(15)
        ->and($copy->storeEnabled)->toBeTrue()
        ->and($copy->streamPartialState)->toBeFalse()
        ->and($copy->nodeFilter)->toBe(['node-a', 'node-b'])
        ->and($copy->eventTypeFilter)->toBe(['text_delta']);
});
