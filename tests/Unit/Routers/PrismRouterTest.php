<?php

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Structured\Request;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Routers\PrismRouter;
use Shahzad\AgenticGraph\State\State;

it('routes via Prism structured output', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    $fake = StructuredResponseFake::make()
        ->withStructured(['route' => 'billing'])
        ->withUsage(new Usage(10, 20))
        ->withMeta(new Meta('fake-1', 'fake-model'));
    Prism::fake([$fake]);

    $router = new PrismRouter([
        'technical' => 'tech',
        'billing' => 'bill',
        'general' => 'gen',
    ], defaultRoute: 'general');

    $state = new State(['message' => 'My invoice issue']);
    $route = $router->route($state);

    expect($route)->toBe('billing');
});

it('builds routing schema with enum constrained to registered route keys', function () {
    $router = new PrismRouter([
        'target_a' => 'billing path',
        'target_b' => 'technical path',
    ], defaultRoute: 'target_b');

    $method = new ReflectionMethod($router, 'buildRoutingSchema');
    $schema = $method->invoke($router);

    expect($schema->name)->toBe('routing_decision')
        ->and($schema->properties)->toHaveCount(1);

    $routeProperty = $schema->properties[0];
    expect($routeProperty)->toBeInstanceOf(EnumSchema::class)
        ->and($routeProperty->name)->toBe('route')
        ->and($routeProperty->options)->toBe(['target_a', 'target_b']);
});

it('returns default route when structured response is missing route key', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    $fake = StructuredResponseFake::make()
        ->withStructured(['reasoning' => 'no route here'])
        ->withUsage(new Usage(5, 5))
        ->withMeta(new Meta('fake-2', 'fake-model'));
    Prism::fake([$fake]);

    $router = new PrismRouter([
        'target_a' => 'a',
        'target_b' => 'b',
    ], defaultRoute: 'target_b');

    expect($router->route(new State))->toBe('target_b');
});

it('returns default route when the LLM call throws after retries', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    Prism::fake([
        fn () => throw new RuntimeException('provider unavailable'),
    ]);

    config()->set('agenticgraph.router.max_retries', 1);

    $router = new PrismRouter([
        'target_a' => 'a',
        'target_b' => 'b',
    ], defaultRoute: 'target_b');

    expect($router->route(new State))->toBe('target_b');
});

it('caches the parsed route keyed by prompt hash', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    config()->set('agenticgraph.prism.cache_enabled', true);
    config()->set('agenticgraph.prism.cache_ttl', 60);

    $fake = StructuredResponseFake::make()
        ->withStructured(['route' => 'billing'])
        ->withUsage(new Usage(10, 20))
        ->withMeta(new Meta('fake-3', 'fake-model'));
    Prism::fake([$fake, $fake]);

    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('has')->once()->andReturn(false);
    $cache->shouldReceive('put')->once()->withArgs(function (string $key, mixed $value) {
        return is_string($key)
            && str_starts_with($key, 'agenticgraph:prism:router:')
            && $value === 'billing';
    })->andReturn(true);
    $cache->shouldReceive('has')->once()->andReturn(true);
    $cache->shouldReceive('get')->once()->andReturn('billing');

    $router = new PrismRouter([
        'billing' => 'bill',
        'general' => 'gen',
    ], defaultRoute: 'general', cache: $cache);

    $state = new State(['message' => 'invoice']);

    expect($router->route($state))->toBe('billing')
        ->and($router->route($state))->toBe('billing');
});

it('scopes cache keys by router class and route set so different routers do not collide', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    config()->set('agenticgraph.prism.cache_enabled', true);
    config()->set('agenticgraph.prism.cache_ttl', 60);
    Cache::flush();

    $sharedPrompt = 'COLLISION_TEST_IDENTICAL_PROMPT';

    $routerA = new class(['writer' => 'writer path', 'critic' => 'critic path']) extends PrismRouter
    {
        protected function buildPrompt(StateContract $state): string
        {
            return 'COLLISION_TEST_IDENTICAL_PROMPT';
        }
    };

    $routerB = new class(['editor' => 'editor path', 'finalize' => 'finalize path']) extends PrismRouter
    {
        protected function buildPrompt(StateContract $state): string
        {
            return 'COLLISION_TEST_IDENTICAL_PROMPT';
        }
    };

    $legacyPromptOnlyKey = 'agenticgraph:prism:router:'.md5($sharedPrompt);
    Cache::put($legacyPromptOnlyKey, 'writer', 3600);

    $fakeWriter = StructuredResponseFake::make()
        ->withStructured(['route' => 'writer'])
        ->withUsage(new Usage(1, 1))
        ->withMeta(new Meta('fake-collision-a', 'fake-model'));
    $fakeEditor = StructuredResponseFake::make()
        ->withStructured(['route' => 'editor'])
        ->withUsage(new Usage(1, 1))
        ->withMeta(new Meta('fake-collision-b', 'fake-model'));
    $prismFake = Prism::fake([$fakeWriter, $fakeEditor]);

    $state = new State([]);

    expect($routerA->route($state))->toBe('writer');

    $routeB = $routerB->route($state);

    expect($routeB)->toBe('editor')
        ->and($routeB)->not->toBe('writer');

    $prismFake->assertRequest(fn (array $requests) => expect($requests)->toHaveCount(2));
});

it('preserves legitimate caching for the same router and prompt', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    config()->set('agenticgraph.prism.cache_enabled', true);
    config()->set('agenticgraph.prism.cache_ttl', 60);
    Cache::flush();

    $fake = StructuredResponseFake::make()
        ->withStructured(['route' => 'billing'])
        ->withUsage(new Usage(10, 20))
        ->withMeta(new Meta('fake-same-router', 'fake-model'));
    $prismFake = Prism::fake([$fake]);

    $router = new PrismRouter([
        'billing' => 'bill',
        'general' => 'gen',
    ], defaultRoute: 'general');

    $state = new State(['message' => 'invoice']);

    expect($router->route($state))->toBe('billing')
        ->and($router->route($state))->toBe('billing');

    $prismFake->assertRequest(fn (array $requests) => expect($requests)->toHaveCount(1));
});

it('keys cache entries distinctly per PrismRouter subclass', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    config()->set('agenticgraph.prism.cache_enabled', true);
    config()->set('agenticgraph.prism.cache_ttl', 60);
    Cache::flush();

    $routerSubclassA = new class(['alpha' => 'alpha path', 'beta' => 'beta path']) extends PrismRouter
    {
        protected function buildPrompt(StateContract $state): string
        {
            return 'SUBCLASS_CACHE_KEY_PROMPT';
        }
    };

    $routerSubclassB = new class(['alpha' => 'alpha path', 'beta' => 'beta path']) extends PrismRouter
    {
        protected function buildPrompt(StateContract $state): string
        {
            return 'SUBCLASS_CACHE_KEY_PROMPT';
        }
    };

    $fakeAlpha = StructuredResponseFake::make()
        ->withStructured(['route' => 'alpha'])
        ->withUsage(new Usage(1, 1))
        ->withMeta(new Meta('fake-subclass-a', 'fake-model'));
    $fakeBeta = StructuredResponseFake::make()
        ->withStructured(['route' => 'beta'])
        ->withUsage(new Usage(1, 1))
        ->withMeta(new Meta('fake-subclass-b', 'fake-model'));
    $prismFake = Prism::fake([$fakeAlpha, $fakeBeta]);

    $state = new State([]);

    expect($routerSubclassA->route($state))->toBe('alpha')
        ->and($routerSubclassB->route($state))->toBe('beta');

    $prismFake->assertRequest(fn (array $requests) => expect($requests)->toHaveCount(2));
});

it('treats a cached route invalid for the current router as a cache miss', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    config()->set('agenticgraph.prism.cache_enabled', true);
    config()->set('agenticgraph.prism.cache_ttl', 60);
    Cache::flush();

    $router = new PrismRouter([
        'target_a' => 'a',
        'target_b' => 'b',
    ], defaultRoute: 'target_b');

    $state = new State(['message' => 'seeded invalid cache']);
    $promptMethod = new ReflectionMethod($router, 'buildPrompt');
    $prompt = $promptMethod->invoke($router, $state);
    $cacheKeyMethod = new ReflectionMethod($router, 'buildCacheKey');
    $cacheKey = $cacheKeyMethod->invoke($router, $prompt);

    Cache::put($cacheKey, 'stale_foreign_route', 3600);

    $fake = StructuredResponseFake::make()
        ->withStructured(['route' => 'target_a'])
        ->withUsage(new Usage(3, 2))
        ->withMeta(new Meta('fake-invalid-cache', 'fake-model'));
    $prismFake = Prism::fake([$fake]);

    expect($router->route($state))->toBe('target_a');

    $prismFake->assertRequest(fn (array $requests) => expect($requests)->toHaveCount(1));
});

it('issues a structured Prism request when routing', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    config()->set('agenticgraph.prism.cache_enabled', false);
    config()->set('agenticgraph.prism.model', 'openai:gpt-4o-mini');

    $fake = StructuredResponseFake::make()
        ->withStructured(['route' => 'technical'])
        ->withUsage(new Usage(8, 4))
        ->withMeta(new Meta('fake-4', 'fake-model'));
    $prismFake = Prism::fake([$fake]);

    $router = new PrismRouter([
        'technical' => 'tech issues',
        'billing' => 'billing issues',
    ], defaultRoute: 'billing');

    expect($router->route(new State(['message' => 'login error'])))->toBe('technical');

    $prismFake->assertRequest(function (array $requests) {
        expect($requests)->toHaveCount(1)
            ->and($requests[0])->toBeInstanceOf(Request::class)
            ->and($requests[0]->provider())->toBe('openai')
            ->and($requests[0]->schema())->not->toBeNull()
            ->and($requests[0]->schema()->name())->toBe('routing_decision');
    });
});

afterEach(function () {
    Cache::flush();
    Mockery::close();
});

it('records from_cache and fallback flags for the last route', function (string $case, array $expected) {
    config()->set('agenticgraph.prism.cache_enabled', true);
    config()->set('agenticgraph.router.max_retries', 1);
    Cache::flush();
    $fake = fn (array $structured): StructuredResponseFake => StructuredResponseFake::make()
        ->withStructured($structured)
        ->withUsage(new Usage(1, 1))
        ->withMeta(new Meta('fake-flags', 'fake-model'));
    Prism::fake(match ($case) {
        'llm', 'cache' => [$fake(['route' => 'target_a'])],
        'missing_key' => [$fake(['reasoning' => 'none'])],
        'invalid_route' => [$fake(['route' => 'nowhere'])],
        'exception' => [fn () => throw new RuntimeException('provider unavailable')],
    });
    $router = new PrismRouter(['target_a' => 'a', 'target_b' => 'b'], defaultRoute: 'target_b');

    $router->route(new State);
    if ($case === 'cache') {
        $router->route(new State);
    }

    expect($router->lastRouteFlags())->toBe($expected);
})->with([
    'llm call' => ['llm', ['from_cache' => false, 'fallback' => false]],
    'cache hit' => ['cache', ['from_cache' => true, 'fallback' => false]],
    'missing route key' => ['missing_key', ['from_cache' => false, 'fallback' => true]],
    'invalid route' => ['invalid_route', ['from_cache' => false, 'fallback' => true]],
    'exception after retries' => ['exception', ['from_cache' => false, 'fallback' => true]],
]);
