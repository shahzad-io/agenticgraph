<?php

namespace Shahzad\AgenticGraph\Routers;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;

class PrismRouter extends AbstractRouter implements RouterInterface
{
    protected ?string $model = 'anthropic:claude-sonnet-4';

    public function __construct(array $routes, ?string $defaultRoute = null)
    {
        $this->routes = $routes;
        $this->defaultRoute = $defaultRoute;
    }

    /**
     * @return string|array
     */
    public function route(StateContract $state): string|array
    {
        $prompt = $this->buildPrompt($state);
        $cacheKey = 'agenticgraph:prism:router:' . md5($prompt);

        // Check cache first
        if (config('agenticgraph.prism.cache_enabled') && \Illuminate\Support\Facades\Cache::has($cacheKey)) {
            return \Illuminate\Support\Facades\Cache::get($cacheKey);
        }

        try {
            [$provider, $model] = $this->parseModel($this->model ?? (string) config('agenticgraph.prism.model'));

            $response = Prism::text()
                ->using($provider, $model)
                ->withPrompt($prompt)
                ->withMaxTokens(100) // Limit response size
                ->asText();

            // Validate response format
            $decoded = json_decode($response->text, true);
            if (!is_array($decoded)) {
                Log::warning('PrismRouter received non-JSON response', [
                    'response' => $response->text,
                    'prompt' => $prompt,
                ]);
                return $this->defaultRoute ?? '';
            }

            if (!isset($decoded['route'])) {
                Log::warning('PrismRouter response missing route key', [
                    'response' => $decoded,
                    'expected_routes' => $this->getAvailableRoutes(),
                ]);
                return $this->defaultRoute ?? '';
            }

            $route = $decoded['route'];

            // Validate route exists
            if (!$this->validateRoute($route)) {
                Log::warning('PrismRouter returned invalid route', [
                    'route' => $route,
                    'available_routes' => $this->getAvailableRoutes(),
                    'defaulting_to' => $this->defaultRoute,
                ]);
                return $this->defaultRoute ?? '';
            }

            // Cache successful result
            if (config('agenticgraph.prism.cache_enabled')) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, $route, config('agenticgraph.prism.cache_ttl', 60) * 60);
            }

            return $route;

        } catch (\Throwable $e) {
            Log::error('PrismRouter failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'state' => $state->toArray(),
                'available_routes' => $this->getAvailableRoutes(),
            ]);

            if (!$this->defaultRoute) {
                throw new \RuntimeException(
                    "PrismRouter failed and no default route configured: " . $e->getMessage(),
                    0,
                    $e
                );
            }

            return $this->defaultRoute;
        }
    }

    protected function buildPrompt(StateContract $state): string
    {
        $routes = collect($this->routes)->map(fn($d, $r) => "- ${r}: ${d}")->join("\n");
        return "Available routes:\n${routes}\n\nCurrent state:\n" . json_encode($state->toArray());
    }

    protected function parseModel(string $value): array
    {
        $parts = explode(':', $value, 2);
        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }
        return ['openai', $value];
    }
}
