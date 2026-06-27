<?php

declare(strict_types=1);

/**
 * Model pricing for LLM cost estimation in traces.
 *
 * UNIT: USD per 1 million tokens (per-1M-tokens) for both `input` and `output`.
 *
 * SOURCE: Baked from OpenRouter model catalog (https://openrouter.ai/api/v1/models).
 * OpenRouter returns USD per token; the sync command converts to per-1M-tokens.
 *
 * REGENERATE (maintainer/dev machine with egress — not at consumer runtime):
 *   php artisan agenticgraph:pricing:sync
 *   php artisan agenticgraph:pricing:sync --fixture=tests/fixtures/openrouter-models-sample.json
 *
 * PUBLISH: php artisan vendor:publish --tag=agenticgraph-config
 *          (publishes config/model_pricing.php for local overrides)
 *
 * @see ModelPricingCalculator
 * @see SyncModelPricingCommand
 */
return [
    'unit' => 'per_million_tokens',
    'currency' => 'USD',
    'source' => 'openrouter',
    'synced_at' => '2026-06-27',
    'fallback_cost_usd' => 0.0,
    'log_unknown_models' => true,

    /*
    |--------------------------------------------------------------------------
    | Model rate table
    |--------------------------------------------------------------------------
    |
    | Keys use OpenRouter identifiers (provider/model). ModelPricingCalculator
    | also resolves agenticgraph event forms like "openai:gpt-4o".
    |
    */
    'models' => [
        'anthropic/claude-3-opus' => ['input' => 15.00, 'output' => 75.00],
        'anthropic/claude-3.5-haiku' => ['input' => 0.80, 'output' => 4.00],
        'anthropic/claude-3.5-sonnet' => ['input' => 3.00, 'output' => 15.00],
        'anthropic/claude-3.7-sonnet' => ['input' => 3.00, 'output' => 15.00],
        'google/gemini-1.5-pro' => ['input' => 1.25, 'output' => 5.00],
        'google/gemini-2.0-flash' => ['input' => 0.10, 'output' => 0.40],
        'meta-llama/llama-3.3-70b-instruct' => ['input' => 0.12, 'output' => 0.30],
        'openai/gpt-4-turbo' => ['input' => 10.00, 'output' => 30.00],
        'openai/gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'openai/gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'openai/o1' => ['input' => 15.00, 'output' => 60.00],
        'openai/o3-mini' => ['input' => 1.10, 'output' => 4.40],
    ],
];
