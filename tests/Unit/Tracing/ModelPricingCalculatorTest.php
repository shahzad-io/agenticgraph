<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Tests\Unit\Tracing\MockPulseExporter;
use Shahzad\AgenticGraph\Tracing\Listeners\TracingListener;
use Shahzad\AgenticGraph\Tracing\ModelPricingCalculator;

beforeEach(function (): void {
    ModelPricingCalculator::resetLoggedUnknownModels();
});

it('calculates cost for a known model from config using per-million token rates', function (): void {
    $calculator = new ModelPricingCalculator;

    $result = $calculator->calculate('openai:gpt-4o', 1_000_000, 500_000);

    expect($result['known'])->toBeTrue()
        ->and($result['pricing_key'])->toBe('openai/gpt-4o')
        ->and($result['cost_usd'])->toBe(7.5); // 2.50 + (0.5 * 10.00)
});

it('resolves openrouter slash and agenticgraph colon model identifiers', function (): void {
    $calculator = new ModelPricingCalculator;

    expect($calculator->resolvePricingKey('openai/gpt-4o-mini'))->toBe('openai/gpt-4o-mini')
        ->and($calculator->resolvePricingKey('openai:gpt-4o-mini'))->toBe('openai/gpt-4o-mini')
        ->and($calculator->resolvePricingKey('gpt-4o-mini'))->toBe('openai/gpt-4o-mini');
});

it('returns fallback cost and logs for unknown models', function (): void {
    Log::spy();

    $calculator = new ModelPricingCalculator;
    $result = $calculator->calculate('totally/unknown-model-xyz', 1000, 1000);

    expect($result['known'])->toBeFalse()
        ->and($result['cost_usd'])->toBe(0.0);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('agenticgraph.model_pricing.unknown_model', Mockery::on(function (array $context): bool {
            return ($context['model'] ?? '') === 'totally/unknown-model-xyz';
        }));
});

it('uses published config overrides for pricing', function (): void {
    config([
        'model_pricing.models' => [
            'custom/vendor-model' => ['input' => 1.0, 'output' => 2.0],
        ],
    ]);

    $calculator = new ModelPricingCalculator;
    $result = $calculator->calculate('custom:vendor-model', 2_000_000, 1_000_000);

    expect($result['known'])->toBeTrue()
        ->and($result['cost_usd'])->toBe(4.0);
});

it('feeds cost_usd on llm spans and llm_calls from the config calculator', function (): void {
    $exporter = new MockPulseExporter;
    $listener = new TracingListener($exporter, new ModelPricingCalculator);

    $listener->onTokensUsed(new TokensUsed(
        node: 'generate',
        promptTokens: 1_000_000,
        completionTokens: 0,
        totalTokens: 1_000_000,
        executionId: 'exec_cost',
        model: 'openai:gpt-4o',
        callType: 'text',
    ));

    expect($exporter->spans[0]['cost_usd'])->toBe(2.5)
        ->and($exporter->llmCalls[0]['cost_usd'])->toBe(2.5);
});

it('prices cached input at the entry cache rates, else at the input rate (usage semantics v2)', function (): void {
    config()->set('model_pricing.models', [
        'vendor/priced' => ['input' => 2.00, 'output' => 8.00, 'cache_read' => 0.50, 'cache_write' => 2.50],
        'vendor/plain' => ['input' => 2.00, 'output' => 8.00],
    ]);
    $calculator = new ModelPricingCalculator;

    // 1M uncached + 1M read + 1M written + 1M out
    expect($calculator->calculate('vendor/priced', 1_000_000, 1_000_000, 1_000_000, 1_000_000)['cost_usd'])->toBe(2.0 + 0.5 + 2.5 + 8.0)
        ->and($calculator->calculate('vendor/plain', 1_000_000, 1_000_000, 1_000_000, 1_000_000)['cost_usd'])->toBe(2.0 + 2.0 + 2.0 + 8.0)
        ->and($calculator->calculate('vendor/plain', 1_000_000, 0, null, null)['cost_usd'])->toBe(2.0);
});

it('ships the OpenAI Standard short-context rates for gpt-6-luna, with cache rates', function (): void {
    // 1M uncached input + 1M cache read + 1M cache write + 1M output, per developers.openai.com/api/docs/pricing
    expect((new ModelPricingCalculator)->calculate('openai:gpt-6-luna', 1_000_000, 1_000_000, 1_000_000, 1_000_000)['cost_usd'])->toBe(0.50 + 0.10 + 0.01 + 0.125);
});
