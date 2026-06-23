<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Routers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
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

    /**
     * @param  array<string, string>  $routes
     */
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

        $maxRetries = (int) config('agenticgraph.router.max_retries', 3);
        $baseDelayMs = (int) config('agenticgraph.router.retry_delay_ms', 100);

        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->executeRouting($prompt, $cacheKey, $state);
            } catch (\Throwable $e) {
                $lastException = $e;

                // Don't retry on certain error types
                if ($this->isNonRetryableError($e)) {
                    break;
                }

                // Log retry attempt
                if ((bool) config('agenticgraph.logging.enabled', true)) {
                    $this->logger->warning('PrismRouter retry attempt', [
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Exponential backoff: 100ms, 200ms, 400ms, etc.
                if ($attempt < $maxRetries) {
                    $delayMs = $baseDelayMs * (2 ** ($attempt - 1));
                    usleep($delayMs * 1000);
                }
            }
        }

        // All retries exhausted or non-retryable error
        return $this->handleRoutingFailure($lastException, $state);
    }

    /**
     * Execute the actual routing logic.
     *
     * @return string|array<int, string>
     */
    private function executeRouting(string $prompt, string $cacheKey, StateContract $state): string|array
    {
        $startTime = microtime(true);
        [$provider, $model] = $this->parseModel($this->model ?? (string) config('agenticgraph.prism.model'));

        $timeout = (int) config('agenticgraph.api.timeout', 30);
        $connectTimeout = (int) config('agenticgraph.api.connect_timeout', 10);

        $response = $this->prismClient()->text()
            ->using($provider, $model)
            ->withPrompt($prompt)
            ->withMaxTokens(100) // Limit response size
            ->withClientOptions([
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
            ])
            ->asText();

        // Dispatch token tracking event
        if ($this->trackTokens) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events->dispatch(TokensUsed::fromLlmCall(
                node: 'PrismRouter',
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $response->usage->promptTokens,
                completionTokens: $response->usage->completionTokens,
                callType: 'router',
                model: "{$provider}:{$model}",
                prompt: $prompt,
                response: $response->text
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
    }

    /**
     * Determine if an error should not be retried.
     */
    private function isNonRetryableError(\Throwable $e): bool
    {
        // Don't retry on validation errors, auth errors, etc.
        $nonRetryablePatterns = [
            'invalid',
            'unauthorized',
            'forbidden',
            'not found',
            'bad request',
            'validation',
        ];

        $message = strtolower($e->getMessage());
        foreach ($nonRetryablePatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle routing failure after all retries exhausted.
     */
    private function handleRoutingFailure(?\Throwable $e, StateContract $state): string
    {
        if ((bool) config('agenticgraph.logging.enabled', true)) {
            $this->logger->error('PrismRouter failed after retries', [
                'exception' => $e?->getMessage(),
                'trace' => $e?->getTraceAsString(),
                'state' => $state->toArray(),
                'available_routes' => $this->getAvailableRoutes(),
            ]);
        }

        if (! $this->defaultRoute) {
            throw new \RuntimeException(
                'PrismRouter failed and no default route configured: '.($e?->getMessage() ?? 'Unknown error'),
                0,
                $e
            );
        }

        return $this->defaultRoute;
    }

    protected function buildPrompt(StateContract $state): string
    {
        $routes = [];
        foreach ($this->routes as $route => $description) {
            $routes[] = "- {$route}: {$description}";
        }
        $routesText = implode("\n", $routes);

        return "Available routes:\n{$routesText}\n\nCurrent state:\n".json_encode($state->toArray());
    }

    /**
     * @return array{0: string, 1: string}
     */
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
