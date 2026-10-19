<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

use Illuminate\Support\Facades\Log;

/**
 * Calculates LLM call cost from the published model_pricing config, with config/model_pricing_overrides.php merged over it.
 */
final class ModelPricingCalculator
{
    /** @var array<string, true> */
    private static array $loggedUnknownModels = [];

    /**
     * @return array{cost_usd: float, pricing_key: ?string, known: bool}
     */
    public function calculate(?string $model, int $promptTokens, int $completionTokens, ?int $cacheReadTokens = null, ?int $cacheWriteTokens = null): array
    {
        if ($model === null || trim($model) === '') {
            return [
                'cost_usd' => $this->fallbackCost(),
                'pricing_key' => null,
                'known' => false,
            ];
        }

        $pricingKey = $this->resolvePricingKey($model);
        if ($pricingKey === null) {
            $this->logUnknownModel($model);

            return [
                'cost_usd' => $this->fallbackCost(),
                'pricing_key' => null,
                'known' => false,
            ];
        }

        $rates = $this->models()[$pricingKey];

        // Usage semantics v2: promptTokens is uncached input. Cached input costs the entry's cache_read / cache_write
        // rate when it has one, else the input rate (what it cost when providers counted it inside the prompt).
        $inputCost = ($promptTokens / 1_000_000) * (float) $rates['input']
            + (($cacheReadTokens ?? 0) / 1_000_000) * (float) ($rates['cache_read'] ?? $rates['input'])
            + (($cacheWriteTokens ?? 0) / 1_000_000) * (float) ($rates['cache_write'] ?? $rates['input']);
        $outputCost = ($completionTokens / 1_000_000) * (float) $rates['output'];

        return [
            'cost_usd' => round($inputCost + $outputCost, 6),
            'pricing_key' => $pricingKey,
            'known' => true,
        ];
    }

    public function resolvePricingKey(string $model): ?string
    {
        $models = $this->models();

        if ($models === []) {
            return null;
        }

        $candidates = array_unique(array_filter([
            $model,
            str_replace(':', '/', $model),
            strtolower(str_replace(':', '/', $model)),
        ]));

        foreach ($candidates as $candidate) {
            if (isset($models[$candidate])) {
                return $candidate;
            }
        }

        $normalized = strtolower(str_replace(':', '/', $model));
        $basename = basename($normalized);

        $matches = [];
        foreach ($models as $key => $_rates) {
            $keyBasename = basename(strtolower($key));

            if ($basename === $keyBasename || str_ends_with(strtolower($key), '/'.$basename)) {
                $matches[] = $key;
            }
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $matches[0];
    }

    /**
     * The sync table with the hand-set overrides over it (an override replaces the whole entry).
     *
     * @return array<string, array{input: float|int, output: float|int, cache_read?: float|int, cache_write?: float|int}>
     */
    private function models(): array
    {
        return array_replace(config('model_pricing.models', []), config('model_pricing_overrides.models', []));
    }

    private function fallbackCost(): float
    {
        return (float) config('model_pricing.fallback_cost_usd', 0.0);
    }

    private function logUnknownModel(string $model): void
    {
        if (! (bool) config('model_pricing.log_unknown_models', true)) {
            return;
        }

        if (isset(self::$loggedUnknownModels[$model])) {
            return;
        }

        self::$loggedUnknownModels[$model] = true;

        Log::warning('agenticgraph.model_pricing.unknown_model', [
            'model' => $model,
            'message' => 'No pricing entry for model; cost_usd set to fallback. Regenerate or publish config/model_pricing.php.',
        ]);
    }

    /**
     * @internal Test helper to reset deduplicated unknown-model logs.
     */
    public static function resetLoggedUnknownModels(): void
    {
        self::$loggedUnknownModels = [];
    }
}
