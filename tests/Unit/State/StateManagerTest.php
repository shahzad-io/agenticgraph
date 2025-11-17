<?php

use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\State\StateManager;

it('diffs states', function () {
    $a = new State(['x' => 1, 'y' => 2]);
    $b = new State(['y' => 3, 'z' => 4]);
    $diff = StateManager::diff($a, $b);
    expect($diff['added'])->toHaveKey('z')
        ->and($diff['removed'])->toHaveKey('x')
        ->and($diff['modified'])->toHaveKey('y');
});

it('encrypts and decrypts state', function () {
    $state = new State(['secret' => 'value']);
    $enc = StateManager::encrypt($state);
    $dec = StateManager::decrypt($enc);
    expect($dec->get('secret'))->toBe('value');
});

it('compresses and decompresses state', function () {
    $state = new State(['payload' => str_repeat('a', 100)]);
    $compressed = StateManager::compress($state);
    $decompressed = StateManager::decompress($compressed);
    expect($decompressed->get('payload'))->toBe(str_repeat('a', 100));
});