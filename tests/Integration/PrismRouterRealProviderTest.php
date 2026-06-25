<?php

use Shahzad\AgenticGraph\Routers\PrismRouter;
use Shahzad\AgenticGraph\State\State;

it('returns a valid registered route via real OpenAI structured output', function () {
    $apiKey = env('OPENAI_API_KEY');
    if (! is_string($apiKey) || $apiKey === '') {
        expect(true)->toBeTrue('OPENAI_API_KEY not configured — skipping real-provider routing test');

        return;
    }

    config()->set('agenticgraph.tracing.enabled', false);
    config()->set('agenticgraph.prism.cache_enabled', false);
    config()->set('agenticgraph.prism.model', 'openai:gpt-4o-mini');
    config()->set('agenticgraph.router.max_retries', 1);

    $router = new PrismRouter([
        'target_a' => 'Route here for billing, invoice, payment, subscription, or refund questions',
        'target_b' => 'Route here for technical bugs, login errors, or software issues',
    ], defaultRoute: 'target_b');

    $state = new State([
        'message' => 'The user is asking about a billing invoice problem with their subscription charge.',
    ]);

    $route = $router->route($state);

    expect($route)->toBe('target_a')
        ->and($route)->not->toBe('target_b');
});
