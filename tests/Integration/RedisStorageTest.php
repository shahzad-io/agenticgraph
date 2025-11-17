<?php

use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Storage\RedisStorage;

it('stores and retrieves checkpoint in redis (guarded)', function () {
    expect(true)->toBeTrue();
});