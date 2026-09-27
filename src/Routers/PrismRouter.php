<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Routers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\LoopAwareRouterInterface;
use Shahzad\AgenticGraph\Contracts\PrismClient;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\LoopContext;
use Shahzad\AgenticGraph\Graph\Graph;

class PrismRouter extends AbstractRouter implements LoopAwareRouterInterface, RouterInterface
{
    protected ?string $model = null;

    protected bool $trackTokens = true;

    protected bool $includeLoopContextInPrompt = true;

    protected ?PrismClient $prismClient = null;

    private Dispatcher $events;

    private LoggerInterface $logger;

    private CacheRepository $cache;

    private Container $container;

    /** @var array{from_cache: bool, fallback: bool} */
    private array $lastRouteFlags = ['from_cache' => false, 'fallback' => false];

    /**
     * @param  array<string, string>  $routes
     */
    public function __construct(array $routes, ?string $defaultRoute = null, ?Dispatcher $events = null, ?LoggerInterface $logger = null, ?CacheRepository $cache = null, ?PrismClient $prismClient = null, ?Container $container = null)
    {
        $this->routes = $routes;
        $this->defaultRoute = $defaultRoute;
        $this->events = $events ?? app(Dispatcher::class);
        $this->logger = $logger ?? app(LoggerInterface::class);
        $this->cache = $cache ?? app(CacheRepository::class);
        $this->prismClient = $prismClient;
        $this->container = $container ?? app();
    }

    public function route(StateContract $state): string|array
    {
        return $this->routeWithLoop($state, $this->resolveLoopContext());
    }

    final public function routeWithContext(StateContract $state, ExecutionContext $context): string|array
    {
        return $this->routeWithLoop($state, LoopContext::from($context));
    }

    /**
     * How the last route was decided, for the route span. Recorded here rather than in
     * afterRoute(), which subclasses override without calling the parent.
     *
     * @return array{from_cache: bool, fallback: bool}
     */
    public function lastRouteFlags(): array
    {
        return $this->lastRouteFlags;
    }

    /**
     * @return string|array<int, string>
     */
    protected function routeWithLoop(StateContract $state, ?LoopContext $loop): string|array
    {
        $this->lastRouteFlags = ['from_cache' => false, 'fallback' => false];
        $prompt = $this->buildPromptWithLoop($state, $loop);
        $cacheKey = $this->buildCacheKey($prompt);

        if (config('agenticgraph.prism.cache_enabled') && $this->cache->has($cacheKey)) {
            $cachedRoute = $this->cache->get($cacheKey);
            if (is_string($cachedRoute) && $this->validateRoute($cachedRoute)) {
                $this->lastRouteFlags['from_cache'] = true;
                $this->afterRoute($state, $cachedRoute, $loop, true);

                return $cachedRoute;
            }

            if (is_string($cachedRoute) && (bool) config('agenticgraph.logging.enabled', true)) {
                $this->logger->warning('PrismRouter cache hit returned invalid route for this router; treating as miss', [
                    'route' => $cachedRoute,
                    'available_routes' => $this->getAvailableRoutes(),
                ]);
            }
        }

        $maxRetries = (int) config('agenticgraph.router.max_retries', 3);
        $baseDelayMs = (int) config('agenticgraph.router.retry_delay_ms', 100);

        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $route = $this->executeRouting($prompt, $cacheKey, $state);
                $this->afterRoute($state, $route, $loop, false);

                return $route;
            } catch (\Throwable $e) {
                $lastException = $e;

                if ($this->isNonRetryableError($e)) {
                    break;
                }

                if ((bool) config('agenticgraph.logging.enabled', true)) {
                    $this->logger->warning('PrismRouter retry attempt', [
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($attempt < $maxRetries) {
                    $delayMs = $baseDelayMs * (2 ** ($attempt - 1));
                    usleep($delayMs * 1000);
                }
            }
        }

        $route = $this->handleRoutingFailure($lastException, $state);
        $this->lastRouteFlags['fallback'] = true;
        $this->afterRoute($state, $route, $loop, false);

        return $route;
    }

    /**
     * Execute the actual routing logic.
     */
    private function executeRouting(string $prompt, string $cacheKey, StateContract $state): string
    {
        $startTime = microtime(true);
        [$provider, $model] = $this->parseModel($this->model ?? (string) config('agenticgraph.prism.model'));

        $timeout = (int) config('agenticgraph.api.timeout', 30);
        $connectTimeout = (int) config('agenticgraph.api.connect_timeout', 10);

        $schema = $this->buildRoutingSchema();

        $response = $this->prismClient()->structured()
            ->using($provider, $model)
            ->withSchema($schema)
            ->withPrompt($prompt)
            ->withMaxTokens(100)
            ->withClientOptions([
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
            ])
            ->asStructured();

        $structured = $response->structured;

        if ($this->trackTokens) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events->dispatch(TokensUsed::fromLlmCall(
                node: 'PrismRouter',
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $response->usage->promptTokens,
                completionTokens: $response->usage->completionTokens,
                callType: 'structured',
                model: "{$provider}:{$model}",
                prompt: $prompt,
                response: is_array($structured)
                    ? json_encode($structured, JSON_THROW_ON_ERROR)
                    : $response->text
            ));
        }

        if (! is_array($structured) || ! isset($structured['route']) || ! is_string($structured['route'])) {
            if ((bool) config('agenticgraph.logging.enabled', true)) {
                $this->logger->warning('PrismRouter structured response missing route key', [
                    'response' => $structured,
                    'expected_routes' => $this->getAvailableRoutes(),
                ]);
            }

            $this->lastRouteFlags['fallback'] = true;

            return $this->defaultRoute ?? '';
        }

        $route = $structured['route'];

        if (! $this->validateRoute($route)) {
            if ((bool) config('agenticgraph.logging.enabled', true)) {
                $this->logger->warning('PrismRouter returned invalid route', [
                    'route' => $route,
                    'available_routes' => $this->getAvailableRoutes(),
                    'defaulting_to' => $this->defaultRoute,
                ]);
            }

            $this->lastRouteFlags['fallback'] = true;

            return $this->defaultRoute ?? '';
        }

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

        return "Choose the best route for the current state.\n\nAvailable routes:\n{$routesText}\n\nCurrent state:\n".json_encode($state->toArray());
    }

    /**
     * Build the routing prompt with optional loop context (author hook).
     * Defaults to buildPrompt() plus gated loop-context augmentation on cyclic graphs.
     */
    protected function buildPromptWithLoop(StateContract $state, ?LoopContext $loop = null): string
    {
        $prompt = $this->buildPrompt($state);

        if ($this->shouldAppendLoopContext($loop)) {
            $prompt .= $this->formatLoopContextForPrompt($loop);
        }

        return $prompt;
    }

    /**
     * Hook for tracing or post-route side effects after a route is resolved.
     *
     * @param  string|array<int, string>  $route
     */
    protected function afterRoute(StateContract $state, string|array $route, ?LoopContext $loop, bool $fromCache): void {}

    protected function shouldAppendLoopContext(?LoopContext $loop): bool
    {
        return $this->includeLoopContextInPrompt
            && $loop !== null
            && $this->graphHasCycles();
    }

    protected function formatLoopContextForPrompt(LoopContext $loop): string
    {
        $routeKeys = array_keys($this->routes);
        $visitCounts = $loop->visitCountsFor($routeKeys);
        $unvisited = $loop->unvisitedAmong($routeKeys);

        $visitSummary = [];
        foreach ($visitCounts as $node => $count) {
            $visitSummary[] = "{$node}:{$count}";
        }

        $lines = [
            '',
            'Loop context:',
            '- Global iterations: '.$loop->globalIterations(),
            '- Route target visits: '.implode(', ', $visitSummary),
        ];

        if ($unvisited !== []) {
            $lines[] = '- Unvisited route targets: '.implode(', ', $unvisited);
        }

        return "\n".implode("\n", $lines);
    }

    protected function graphHasCycles(): bool
    {
        if (! $this->container->bound(Graph::class)) {
            return false;
        }

        return $this->container->make(Graph::class)->hasCycles();
    }

    protected function buildCacheKey(string $prompt): string
    {
        $identity = static::class.':'.implode(',', array_keys($this->routes));

        return 'agenticgraph:prism:router:'.md5($identity.':'.$prompt);
    }

    protected function buildRoutingSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'routing_decision',
            description: 'Select the route key that best matches the current state',
            properties: [
                new EnumSchema(
                    name: 'route',
                    description: 'The route key to take next',
                    options: $this->getAvailableRoutes(),
                ),
            ],
            requiredFields: ['route'],
        );
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

    private function resolveLoopContext(): ?LoopContext
    {
        if (! $this->container->bound(ExecutionContext::class)) {
            return null;
        }

        return LoopContext::from($this->container->make(ExecutionContext::class));
    }
}
