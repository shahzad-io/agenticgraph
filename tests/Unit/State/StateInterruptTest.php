<?php

use Shahzad\AgenticGraph\State\State;

it('can set and check interrupt', function () {
    $state = new State;
    expect($state->hasInterrupt())->toBeFalse;

    $state = $state->setInterrupt('Approval needed', 'checkpoint-123');

    expect($state->hasInterrupt())->toBeTrue;
    expect($state->getInterruptCheckpointId())->toBe('checkpoint-123');
});

it('can clear interrupt', function () {
    $state = (new State)->setInterrupt('Test', 'cp-1');
    expect($state->hasInterrupt())->toBeTrue;

    $state = $state->clearInterrupt();
    expect($state->hasInterrupt())->toBeFalse;
});

it('returns interrupt metadata correctly', function () {
    $state = new State;
    $state = $state->setInterrupt('Needs review', 'cp-abc-123');

    $interrupt = $state->getInterrupt();

    expect($interrupt)->toBeArray;
    expect($interrupt['reason'])->toBe('Needs review');
    expect($interrupt['checkpoint_id'])->toBe('cp-abc-123');
    expect($interrupt)->toHaveKey('created_at');
});

it('returns null for getInterrupt when no interrupt set', function () {
    $state = new State;

    expect($state->getInterrupt())->toBeNull;
});

it('returns null for getInterruptCheckpointId when no interrupt set', function () {
    $state = new State;

    expect($state->getInterruptCheckpointId())->toBeNull;
});

it('maintains immutability when setting interrupt', function () {
    $state1 = new State;
    $state2 = $state1->setInterrupt('Test', 'cp-1');

    expect($state1)->not->toBe($state2);
    expect($state1->hasInterrupt())->toBeFalse;
    expect($state2->hasInterrupt())->toBeTrue;
});

it('maintains immutability when clearing interrupt', function () {
    $state1 = (new State)->setInterrupt('Test', 'cp-1');
    $state2 = $state1->clearInterrupt();

    expect($state1)->not->toBe($state2);
    expect($state1->hasInterrupt())->toBeTrue;
    expect($state2->hasInterrupt())->toBeFalse;
});

it('preserves other state data when setting interrupt', function () {
    $state = new State(['user' => 'John', 'count' => 5]);
    $state = $state->setInterrupt('Approval', 'cp-1');

    expect($state->get('user'))->toBe('John');
    expect($state->get('count'))->toBe(5);
    expect($state->hasInterrupt())->toBeTrue;
});

it('preserves other state data when clearing interrupt', function () {
    $state = new State(['user' => 'Jane']);
    $state = $state->setInterrupt('Test', 'cp-1');
    $state = $state->set('task', 'processing');
    $state = $state->clearInterrupt();

    expect($state->get('user'))->toBe('Jane');
    expect($state->get('task'))->toBe('processing');
    expect($state->hasInterrupt())->toBeFalse;
});

it('uses dot notation for checkpoint id access', function () {
    $state = new State;
    $state = $state->setInterrupt('Test', 'checkpoint-deep-123');

    // Should work with dot notation via get()
    expect($state->get('_interrupt.checkpoint_id'))->toBe('checkpoint-deep-123');
});
