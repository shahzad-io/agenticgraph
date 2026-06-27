<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

/**
 * Maps OpenRouter /api/v1/models JSON to model_pricing config entries.
 *
 * OpenRouter pricing fields are USD per token (string). This mapper converts
 * to USD per 1 million tokens to match ModelPricingCalculator.
 */
final class OpenRouterPricingMapper
{
    /**
     * @param  array<string, mixed>  $response
     * @return array<string, array{input: float, output: float}>
     */
    public function mapResponseToModels(array $response): array
    {
        $models = [];
        $data = $response['data'] ?? [];

        if (! is_array($data)) {
            return [];
        }

        foreach ($data as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = $entry['id'] ?? null;
            $pricing = $entry['pricing'] ?? null;

            if (! is_string($id) || $id === '' || ! is_array($pricing)) {
                continue;
            }

            $input = $this->toPerMillionUsd($pricing['prompt'] ?? 0);
            $output = $this->toPerMillionUsd($pricing['completion'] ?? 0);

            if ($input === null || $output === null) {
                continue;
            }

            $models[$id] = [
                'input' => $input,
                'output' => $output,
            ];
        }

        ksort($models);

        return $models;
    }

    public function toPerMillionUsd(string|int|float|null $openRouterPrice): ?float
    {
        if ($openRouterPrice === null || $openRouterPrice === '') {
            return 0.0;
        }

        $value = (float) $openRouterPrice;

        if ($value < 0) {
            return null;
        }

        if ($value === 0.0) {
            return 0.0;
        }

        // OpenRouter documents per-token USD (e.g. 0.0000025 for gpt-4o input).
        if ($value < 0.01) {
            return round($value * 1_000_000, 6);
        }

        // Some responses may already be per-million; keep as-is.
        return round($value, 6);
    }
}
