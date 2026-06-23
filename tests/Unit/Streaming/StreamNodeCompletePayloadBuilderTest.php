<?php

use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\State\StateManager;
use Shahzad\AgenticGraph\Streaming\StreamNodeCompletePayloadBuilder;

it('returns only lifecycle fields when capture mode is none', function () {
    $manager = app(StateManager::class);
    $builder = new StreamNodeCompletePayloadBuilder($manager, 'none');

    $payload = $builder->build('node-a', 12.5, new State, new State(['x' => 1]));

    expect($payload)->toBe([
        'node_name' => 'node-a',
        'duration_ms' => 12.5,
    ]);
});

it('embeds state diff when capture mode is diff', function () {
    $manager = app(StateManager::class);
    $builder = new StreamNodeCompletePayloadBuilder($manager, 'diff');

    $payload = $builder->build(
        'node-a',
        5.0,
        new State(['k' => 'old']),
        new State(['k' => 'new', 'extra' => ['nested' => [1, 2]]])
    );

    expect($payload['state_diff']['modified']['k'])->toBe(['from' => 'old', 'to' => 'new'])
        ->and($payload['state_diff']['added']['extra'])->toBe(['nested' => [1, 2]]);
});

it('embeds toStoragePayload state when capture mode is full', function () {
    $manager = app(StateManager::class);
    $builder = new StreamNodeCompletePayloadBuilder($manager, 'full');

    $payload = $builder->build('node-a', 1.0, new State, new State(['done' => true]));

    expect($payload['state'])->toBe(State::toStoragePayload(new State(['done' => true])));
});
