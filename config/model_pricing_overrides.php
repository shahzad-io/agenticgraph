<?php

declare(strict_types=1);

/**
 * Hand-set model pricing. Entries here win over config/model_pricing.php (ModelPricingCalculator merges them over
 * it) and agenticgraph:pricing:sync never writes this file, so a sync cannot drop or replace them.
 *
 * Same shape as the sync table: USD per 1 million tokens, keyed by OpenRouter-style id. Besides `input` and `output`
 * an entry may set `cache_read` and `cache_write` (cached input costs the `input` rate without them). Any other key
 * (for example `note`) is ignored by the calculator.
 *
 * PUBLISH: php artisan vendor:publish --tag=agenticgraph-config
 */
return [
    'models' => [
        // OpenAI Standard tier, short context, read 2026-10-19 at https://developers.openai.com/api/docs/pricing.
        // Long context (above 272K input tokens) costs more (input 0.20, output 0.75) and is not modelled.
        // TODO(shahzad): verify the cache_write rate (0.125) on the official pricing page; it was read through a page summary.
        'openai/gpt-6-luna' => [
            'input' => 0.10,
            'output' => 0.50,
            'cache_read' => 0.01,
            'cache_write' => 0.125,
            'note' => 'short context (up to 272K input)',
        ],
    ],
];
