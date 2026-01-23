<?php

use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Routers\PrismRouter;
use Shahzad\AgenticGraph\State\State;

it('routes via Prism structured output', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }
    $fake = TextResponseFake::make()
        ->withText(json_encode(['route' => 'billing', 'reasoning' => 'Detected billing intent']))
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
