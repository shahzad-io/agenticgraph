<?php

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
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
            ->and($requests[0])->toBeInstanceOf(\Prism\Prism\Structured\Request::class)
            ->and($requests[0]->provider())->toBe('openai')
            ->and($requests[0]->schema())->not->toBeNull()
            ->and($requests[0]->schema()->name())->toBe('routing_decision');
    });
});

afterEach(function () {
    Cache::flush();
    Mockery::close();
});
