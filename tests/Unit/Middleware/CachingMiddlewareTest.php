<?php

use Illuminate\Support\Facades\Cache;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Middleware\CachingMiddleware;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

it('returns a cached state from a store that refuses to unserialize objects (Laravel 13 default)', function () {
    config(['cache.serializable_classes' => false, 'cache.stores.probe' => ['driver' => 'file', 'path' => sys_get_temp_dir().'/ag-caching-'.uniqid()]]);
    Cache::setDefaultDriver('probe');
    $node = new class extends AbstractNode
    {
        protected ?string $name = 'cached';

        public function execute(StateContract $state): StateContract
        {
            return $state;
        }
    };
    $calls = 0;
    $next = function (StateContract $state) use (&$calls): StateContract {
        $calls++;

        return $state->set('answer', 42);
    };

    (new CachingMiddleware)->handle(new State(['q' => 1]), $node, $next);
    $cached = (new CachingMiddleware)->handle(new State(['q' => 1]), $node, $next);

    expect($calls)->toBe(1)
        ->and($cached)->toBeInstanceOf(State::class)
        ->and($cached->get('answer'))->toBe(42);
});
