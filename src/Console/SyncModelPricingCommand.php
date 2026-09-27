<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Shahzad\AgenticGraph\Tracing\ModelPricingConfigWriter;
use Shahzad\AgenticGraph\Tracing\OpenRouterPricingMapper;

/**
 * Maintainer tooling: fetch OpenRouter pricing and regenerate config/model_pricing.php.
 *
 * Requires egress to https://openrouter.ai; run on a dev machine or CI job, not in
 * locked-down sandboxes. Use --fixture for offline mapping tests.
 */
final class SyncModelPricingCommand extends Command
{
    protected $signature = 'agenticgraph:pricing:sync
                            {--output= : Absolute or package-relative path for model_pricing.php}
                            {--fixture= : Local OpenRouter JSON fixture instead of live fetch}';

    protected $description = 'Regenerate config/model_pricing.php from OpenRouter model pricing';

    public function handle(OpenRouterPricingMapper $mapper, ModelPricingConfigWriter $writer): int
    {
        $fixture = $this->option('fixture');

        if (is_string($fixture) && $fixture !== '') {
            if (! is_file($fixture)) {
                $this->error("Fixture not found: {$fixture}");

                return self::FAILURE;
            }

            $decoded = json_decode((string) file_get_contents($fixture), true);
            if (! is_array($decoded)) {
                $this->error('Fixture JSON is invalid.');

                return self::FAILURE;
            }

            $response = $decoded;
            $this->info("Using fixture: {$fixture}");
        } else {
            $this->info('Fetching OpenRouter model catalog...');

            try {
                $httpResponse = Http::timeout(60)->get('https://openrouter.ai/api/v1/models');
            } catch (\Throwable $e) {
                $this->error('OpenRouter fetch failed: '.$e->getMessage());
                $this->line('Use --fixture=tests/fixtures/openrouter-models-sample.json for offline regeneration tests.');

                return self::FAILURE;
            }

            if (! $httpResponse->successful()) {
                $this->error('OpenRouter returned HTTP '.$httpResponse->status());

                return self::FAILURE;
            }

            $response = $httpResponse->json();
            if (! is_array($response)) {
                $this->error('OpenRouter response was not valid JSON.');

                return self::FAILURE;
            }
        }

        $models = $mapper->mapResponseToModels($response);

        if ($models === []) {
            $this->error('No models mapped from OpenRouter response.');

            return self::FAILURE;
        }

        $output = $this->resolveOutputPath();
        $writer->write($output, $models);

        $this->info('Wrote '.count($models)." models to {$output}");
        $this->line('Publish for apps: php artisan vendor:publish --tag=agenticgraph-config');

        return self::SUCCESS;
    }

    private function resolveOutputPath(): string
    {
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            return $output;
        }

        return dirname(__DIR__, 2).'/config/model_pricing.php';
    }
}
