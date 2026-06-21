<?php

use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;

test('stream session serializes with seq', function () {
    $options = new StreamOptions(delivery: 'iterator');
    $session = new StreamSession(
        streamId: 'stream-1',
        executionId: 'exec-1',
        options: $options,
        startedAtUnix: 123456,
        seq: 7
    );

    $copy = unserialize(serialize($session));

    expect($copy)->toBeInstanceOf(StreamSession::class)
        ->and($copy->streamId)->toBe('stream-1')
        ->and($copy->executionId)->toBe('exec-1')
        ->and($copy->options->delivery)->toBe('iterator')
        ->and($copy->startedAtUnix)->toBe(123456)
        ->and($copy->seq)->toBe(7);
});
