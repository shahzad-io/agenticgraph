<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Routers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\PrismClient;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;

class PrismRouter extends AbstractRouter implements RouterInterface
{
    protected ?string $model = null;

    protected bool $trackTokens = true;

    protected ?PrismClient $prismClient = null;

    private Dispatcher $events;

    private LoggerInterface $logger;

    private CacheRepository $cache;

    public function __construct(array $routes, ?string $defaultRoute = null, ?Dispatcher $events = null, ?LoggerInterface $logger = null, ?CacheRepository $cache = null, ?PrismClient $prismClient = null)
    {
        $this->routes = $routes;
        $this->defaultRoute = $defaultRoute;
        $this->events = $events ?? app(Dispatcher::class);
        $this->logger = $logger ?? app(LoggerInterface::class);
        $this->cache = $cache ?? app(CacheRepository::class);
        $this->prismClient = $prismClient;
    }

    public function route(StateContract $state): string|array
    {
        $prompt = $this->buildPrompt($state);
        $cacheKey = 'agenticgraph:prism:router:'.md5($prompt);

        // Check cache first
        if (config('agenticgraph.prism.cache_enabled') && $this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }

        try {
            $startTime = microtime(true);
            [$provider, $model] = $this->parseModel($this->model ?? (string) config('agenticgraph.prism.model'));

            $response = $this->prismClient()->text()
                ->using($provider, $model)
                ->withPrompt($prompt)
                ->withMaxTokens(100) // Limit response size
                ->asText();

            // Dispatch token tracking event
            if ($this->trackTokens && isset($response->usage)) {
                $durationMs = (microtime(true) - $startTime) * 1000;
                $this->events->dispatch(new TokensUsed(
                    node: 'PrismRouter',
                    promptTokens: $response->usage->promptTokens ?? 0,
                    completionTokens: $response->usage->completionTokens ?? 0,
                    totalTokens: ($response->usage->promptTokens ?? 0) + ($response->usage->completionTokens ?? 0),
                    callType: 'router',
                    model: "{$provider}:{$model}",
                    durationMs: $durationMs
                ));
            }

            // Validate response format
            $decoded = json_decode($response->text, true);
            if (! is_array($decoded)) {
                if ((bool) config('agenticgraph.logging.enabled', true)) {
                    $this->logger->warning('PrismRouter received non-JSON response', [
                        'response' => $response->text,
                        'prompt' => $prompt,
                    ]);
                }

                return $this->defaultRoute ?? '';
            }

            if (! isset($decoded['route'])) {
                if ((bool) config('agenticgraph.logging.enabled', true)) {
                    $this->logger->warning('PrismRouter response missing route key', [
                        'response' => $decoded,
                        'expected_routes' => $this->getAvailableRoutes(),
                    ]);
                }

                return $this->defaultRoute ?? '';
            }

            $route = $decoded['route'];

            // Validate route exists
            if (! $this->validateRoute($route)) {
                if ((bool) config('agenticgraph.logging.enabled', true)) {
                    $this->logger->warning('PrismRouter returned invalid route', [
                        'route' => $route,
                        'available_routes' => $this->getAvailableRoutes(),
                        'defaulting_to' => $this->defaultRoute,
                    ]);
                }

                return $this->defaultRoute ?? '';
            }

            // Cache successful result
            if (config('agenticgraph.prism.cache_enabled')) {
                $this->cache->put($cacheKey, $route, config('agenticgraph.prism.cache_ttl', 60) * 60);
            }

            return $route;

        } catch (\Throwable $e) {
            if ((bool) config('agenticgraph.logging.enabled', true)) {
                $this->logger->error('PrismRouter failed', [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'state' => $state->toArray(),
                    'available_routes' => $this->getAvailableRoutes(),
                ]);
            }

            if (! $this->defaultRoute) {
                throw new \RuntimeException(
                    'PrismRouter failed and no default route configured: '.$e->getMessage(),
                    0,
                    $e
                );
            }

            return $this->defaultRoute;
        }
    }

    protected function buildPrompt(StateContract $state): string
    {
        $routes = collect($this->routes)->map(fn ($d, $r) => "- ${r}: ${d}")->join("\n");

        return "Available routes:\n${routes}\n\nCurrent state:\n".json_encode($state->toArray());
    }

    protected function parseModel(string $value): array
    {
        $parts = explode(':', $value, 2);
        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        return ['openai', $value];
    }

    protected function prismClient(): PrismClient
    {
        if ($this->prismClient !== null) {
            return $this->prismClient;
        }

        return app(PrismClient::class);
    }
}
