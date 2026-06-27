<?php

declare(strict_types=1);

use Shahzad\AgenticGraph\Tracing\ModelPricingConfigWriter;
use Shahzad\AgenticGraph\Tracing\OpenRouterPricingMapper;

it('maps openrouter per-token pricing to per-million config rates', function (): void {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../fixtures/openrouter-models-sample.json'),
        true
    );

    expect($fixture)->toBeArray();

    $mapper = new OpenRouterPricingMapper;
    $models = $mapper->mapResponseToModels($fixture);

    expect($models)->toHaveKey('openai/gpt-4o')
        ->and($models['openai/gpt-4o']['input'])->toBe(2.5)
        ->and($models['openai/gpt-4o']['output'])->toBe(10.0)
        ->and($models['openai/gpt-4o-mini']['input'])->toBe(0.15)
        ->and($models['openai/gpt-4o-mini']['output'])->toBe(0.6)
        ->and($models['anthropic/claude-3.5-sonnet']['input'])->toBe(3.0)
        ->and($models['anthropic/claude-3.5-sonnet']['output'])->toBe(15.0);
});

it('renders a writable model_pricing config file from mapped models', function (): void {
    $writer = new ModelPricingConfigWriter;
    $path = sys_get_temp_dir().'/agenticgraph-pricing-render-'.uniqid('', true).'.php';

    $writer->write($path, [
        'openai/gpt-4o' => ['input' => 2.5, 'output' => 10.0],
    ], '2026-01-01');

    $content = (string) file_get_contents($path);
    expect($content)->toContain('per_million_tokens')
        ->and($content)->toContain("'openai/gpt-4o' => ['input' => 2.5, 'output' => 10]");

    /** @var array<string, mixed> $loaded */
    $loaded = require $path;
    expect($loaded['models']['openai/gpt-4o']['input'])->toBe(2.5);

    @unlink($path);
});

it('sync command regenerates config from fixture without live openrouter fetch', function (): void {
    $output = sys_get_temp_dir().'/agenticgraph-model-pricing-'.uniqid('', true).'.php';
    $fixture = __DIR__.'/../../fixtures/openrouter-models-sample.json';

    $this->artisan('agenticgraph:pricing:sync', [
        '--fixture' => $fixture,
        '--output' => $output,
    ])->assertSuccessful();

    expect(is_file($output))->toBeTrue();

    /** @var array<string, mixed> $config */
    $config = require $output;

    expect($config['models']['openai/gpt-4o']['input'] ?? null)->toBe(2.5)
        ->and($config['source'])->toBe('openrouter');

    @unlink($output);
});

it('loads default model_pricing config from the package', function (): void {
    expect(config('model_pricing.unit'))->toBe('per_million_tokens')
        ->and(config('model_pricing.models.openai/gpt-4o.input'))->toBe(2.5);
});
