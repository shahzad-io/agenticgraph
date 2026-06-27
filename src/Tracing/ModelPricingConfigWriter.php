<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

/**
 * Writes config/model_pricing.php from a mapped models array.
 */
final class ModelPricingConfigWriter
{
    /**
     * @param  array<string, array{input: float, output: float}>  $models
     */
    public function render(array $models, ?string $syncedAt = null): string
    {
        $syncedAt ??= (new \DateTimeImmutable)->format('Y-m-d');

        $modelLines = [];
        foreach ($models as $id => $rates) {
            $input = $this->formatNumber($rates['input']);
            $output = $this->formatNumber($rates['output']);
            $modelLines[] = "        '{$id}' => ['input' => {$input}, 'output' => {$output}],";
        }

        $modelsBlock = implode("\n", $modelLines);

        return <<<PHP
<?php

declare(strict_types=1);

/**
 * Model pricing for LLM cost estimation in traces.
 *
 * UNIT: USD per 1 million tokens (per-1M-tokens) for both `input` and `output`.
 *
 * SOURCE: Generated from OpenRouter (https://openrouter.ai/api/v1/models).
 *
 * REGENERATE (maintainer/dev machine with egress):
 *   php artisan agenticgraph:pricing:sync
 */
return [
    'unit' => 'per_million_tokens',
    'currency' => 'USD',
    'source' => 'openrouter',
    'synced_at' => '{$syncedAt}',
    'fallback_cost_usd' => 0.0,
    'log_unknown_models' => true,

    'models' => [
{$modelsBlock}
    ],
];

PHP;
    }

    /**
     * @param  array<string, array{input: float, output: float}>  $models
     */
    public function write(string $path, array $models, ?string $syncedAt = null): void
    {
        $content = $this->render($models, $syncedAt);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create directory: {$directory}");
        }

        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Unable to write pricing config: {$path}");
        }
    }

    private function formatNumber(float $value): string
    {
        $formatted = number_format($value, 6, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
