<?php

use Shahzad\AgenticGraph\State\State;

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

it('handles deep nesting up to max depth', function () {
    // Create deeply nested structure (more than MAX_MERGE_DEPTH = 10)
    $deep1 = [];
    $deep2 = [];
    $current1 = &$deep1;
    $current2 = &$deep2;

    for ($i = 0; $i < 15; $i++) {
        $current1['level'] = [];
        $current1 = &$current1['level'];
        $current2['level'] = [];
        $current2 = &$current2['level'];
    }

    // Set different values at the deepest level
    $deep1['value'] = 'original';
    $deep2['value'] = 'merged';

    $state1 = new State($deep1);
    $state2 = new State($deep2);

    // Should not throw, but stop merging at max depth
    $merged = $state1->merge($state2);
    expect($merged)->toBeInstanceOf(State::class);
});

it('handles merge conflicts by preferring later state', function () {
    $a = new State(['key' => 'value_a', 'shared' => 'from_a']);
    $b = new State(['key' => 'value_b', 'shared' => 'from_b']);

    $merged = $a->merge($b);

    // Later state ($b) values should win
    expect($merged->get('key'))->toBe('value_b');
    expect($merged->get('shared'))->toBe('from_b');
});

it('handles merge conflicts with nested arrays', function () {
    $a = new State([
        'config' => [
            'debug' => true,
            'timeout' => 30,
            'nested' => ['a' => 1, 'b' => 2],
        ],
    ]);

    $b = new State([
        'config' => [
            'debug' => false,
            'retries' => 3,
            'nested' => ['b' => 3, 'c' => 4],
        ],
    ]);

    $merged = $a->merge($b);
    $result = $merged->toArray();

    // Top-level merge
    expect($result['config']['debug'])->toBe(false);
    expect($result['config']['timeout'])->toBe(30);
    expect($result['config']['retries'])->toBe(3);

    // Nested associative array merge
    expect($result['config']['nested'])->toBe([
        'a' => 1,
        'b' => 3,
        'c' => 4,
    ]);
});

it('handles merging with objects that implement JsonSerializable', function () {
    $obj = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            return ['serialized' => 'data'];
        }
    };

    $a = new State(['data' => 'original']);
    $b = new State(['data' => $obj]);

    // Objects should be replaced, not merged
    $merged = $a->merge($b);
    expect($merged->get('data'))->toBe($obj);
});

it('handles merging numeric arrays by replacement', function () {
    $a = new State(['items' => [1, 2, 3]]);
    $b = new State(['items' => [4, 5]]);

    $merged = $a->merge($b);

    // Numeric arrays should be replaced, not merged
    expect($merged->get('items'))->toBe([4, 5]);
});

it('handles merging mixed types', function () {
    $a = new State([
        'string_to_int' => 'hello',
        'int_to_array' => 42,
        'array_to_null' => ['a', 'b'],
    ]);

    $b = new State([
        'string_to_int' => 123,
        'int_to_array' => [1, 2, 3],
        'array_to_null' => null,
    ]);

    $merged = $a->merge($b);

    expect($merged->get('string_to_int'))->toBe(123);
    expect($merged->get('int_to_array'))->toBe([1, 2, 3]);
    expect($merged->get('array_to_null'))->toBeNull();
});
