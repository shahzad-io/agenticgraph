<?php

use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\State\StateValidationException;

it('gets and sets values immutably', function () {
    $state1 = new State(['count' => 1]);
    $state2 = $state1->set('count', 2);
    expect($state1)->not->toBe($state2)
        ->and($state1->get('count'))->toBe(1)
        ->and($state2->get('count'))->toBe(2);
});

it('supports dot notation', function () {
    $state = new State(['user' => ['name' => 'John']]);
    expect($state->get('user.name'))->toBe('John');
});

it('merges states', function () {
    $a = new State(['x' => 1, 'y' => 2]);
    $b = new State(['y' => 3, 'z' => 4]);
    $merged = $a->merge($b);
    expect($merged->toArray())->toBe(['x' => 1, 'y' => 3, 'z' => 4]);
});