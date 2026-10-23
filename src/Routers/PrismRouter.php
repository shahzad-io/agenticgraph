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
use Shahzad\AgenticGraph\Events\RouteFellBack;
use Shahzad\AgenticGraph\Exceptions\MissingModelException;
use Shahzad\AgenticGraph\Exceptions\RoutingException;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\LoopContext;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Llm\LlmCall;
use Shahzad\AgenticGraph\Llm\LlmExecutor;
use Shahzad\AgenticGraph\Prompts\ChatPromptMessages;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\PromptFile;

class PrismRouter extends AbstractRouter implements LoopAwareRouterInterface, RouterInterface
{
    protected ?string $model = null;

    /** Max output tokens for the routing call; null falls back to agenticgraph.router.max_tokens, then 1024. */
    protected ?int $maxTokens = null;

    /** Reasoning level for the routing call: off, low, medium or high; null falls back to agenticgraph.router.reasoning. */
    protected ?string $reasoning = null;

    protected bool $trackTokens = true;

    protected bool $includeLoopContextInPrompt = true;

    protected ?PrismClient $prismClient = null;

    /** The routing prompt as a prompt file (Prompts::get()) or chat template, formatted with routingVars(); null uses buildPrompt(). */
    protected ?ChatPromptTemplate $prompt = null;

    private Dispatcher $events;

    private LoggerInterface $logger;

    private CacheRepository $cache;

    private Container $container;

    /** @var array{from_cache: bool, fallback: bool} */
    private array $lastRouteFlags = ['from_cache' => false, 'fallback' => false];

    /**
     * @param  array<string, string>  $routes
     * @param  ChatPromptTemplate|null  $prompt  a prompt file (Prompts::get()) or chat template over routingVars(); null uses buildPrompt()
     */
    public function __construct(array $routes, ?string $defaultRoute = null, ?Dispatcher $events = null, ?LoggerInterface $logger = null, ?CacheRepository $cache = null, ?PrismClient $prismClient = null, ?Container $container = null, ?ChatPromptTemplate $prompt = null)
    {
        $this->prompt = $prompt;
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
        [$call, $builder] = $this->routingCall($state, $loop);
        $cacheKey = $this->buildCacheKey((string) $call->promptForTrace());

        if (config('agenticgraph.prism.cache_enabled', false) && $this->cache->has($cacheKey)) {
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
                $route = $this->executeRouting($call, $builder, $cacheKey);
                $this->afterRoute($state, $route, $loop, false);

                return $route;
            } catch (MissingModelException|RoutingException $e) {
                throw $e; // configuration error, or an unusable reply with no default route: no retry
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

        $this->logRoutingFailure($lastException, $state);
        $route = $this->fellBack('routing_failed', $lastException?->getMessage() ?? 'Unknown error', $lastException);
        $this->afterRoute($state, $route, $loop, false);

        return $route;
    }

    /**
     * The routing request: buildPromptWithLoop() as the prompt, or $prompt formatted with routingVars() and the loop
     * context appended to its last message. The router's own cache is checked in routeWithLoop(); routing never
     * streams. Max tokens and reasoning come from executor(): the class properties, then agenticgraph.router.
     *
     * @return array{0: LlmCall, 1: array{vars?: array<string, mixed>, template_vars?: list<string>}}
     */
    private function routingCall(StateContract $state, ?LoopContext $loop): array
    {
        $settings = ['schema' => $this->buildRoutingSchema(), 'cache' => false, 'stream' => false, 'structuredTraceAsJson' => true, 'structuredRetries' => 0];
        if ($this->prompt === null) {
            return [new LlmCall(...$settings, prompt: $this->buildPromptWithLoop($state, $loop)), []];
        }

        $vars = $this->routingVars($state);
        $messages = $this->prompt->format($vars);
        if ($this->shouldAppendLoopContext($loop)) {
            $last = array_key_last($messages);
            if ($last === null || isset($messages[$last]['message'])) {
                $messages[] = ['role' => 'user', 'content' => ltrim($this->formatLoopContextForPrompt($loop))];
            } else {
                $messages[$last]['content'] .= $this->formatLoopContextForPrompt($loop);
            }
        }

        return [
            new LlmCall(...$settings, messages: ChatPromptMessages::toPrismMessages($messages), promptFile: $this->prompt instanceof PromptFile ? $this->prompt->meta() : null),
            ['vars' => $vars, 'template_vars' => $this->prompt->getInterpolatedVariables()],
        ];
    }

    /**
     * Execute the actual routing logic.
     *
     * @param  array{vars?: array<string, mixed>, template_vars?: list<string>}  $builder
     */
    private function executeRouting(LlmCall $call, array $builder, string $cacheKey): string
    {
        $structured = $this->executor()->run($call, $builder)->structured;

        if (! is_array($structured) || ! isset($structured['route']) || ! is_string($structured['route'])) {
            if ((bool) config('agenticgraph.logging.enabled', true)) {
                $this->logger->warning('PrismRouter structured response missing route key', [
                    'router' => static::class,
                    'response_type' => get_debug_type($structured),
                    'response_keys' => is_array($structured) ? array_keys($structured) : [],
                    'route_valid' => false,
                    'expected_routes' => $this->getAvailableRoutes(),
                ]);
            }

            return $this->fellBack('missing_route_key', (string) json_encode($structured));
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

            return $this->fellBack('invalid_route', (string) json_encode($structured));
        }

        if (config('agenticgraph.prism.cache_enabled', false)) {
            $this->cache->put($cacheKey, $route, config('agenticgraph.prism.cache_ttl', 60) * 60);
        }

        return $route;
    }

    /**
     * Takes the default route: marks it as a fallback (route.fallback on the span), logs a warning and dispatches
     * RouteFellBack. Without a default route, throws RoutingException with the reason and the reply.
     */
    private function fellBack(string $reason, string $reply, ?\Throwable $previous = null): string
    {
        if (! $this->defaultRoute) {
            throw new RoutingException(static::class, $reason, $reply, $previous);
        }

        $route = $this->defaultRoute;
        $error = $reason === 'routing_failed' ? $reply : null;
        $this->lastRouteFlags['fallback'] = true;
        if ((bool) config('agenticgraph.logging.enabled', true)) {
            $this->logger->warning('PrismRouter fell back to the default route', [
                'router' => static::class,
                'default_route' => $route,
                'reason' => $reason,
                'error' => $error,
            ]);
        }
        $this->events->dispatch(new RouteFellBack(static::class, $route, $reason, $error));

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
     * Logs the routing failure after all retries are used up.
     */
    private function logRoutingFailure(?\Throwable $e, StateContract $state): void
    {
        if ((bool) config('agenticgraph.logging.enabled', true)) {
            $this->logger->error('PrismRouter failed after retries', [
                'router' => static::class,
                'exception_class' => $e !== null ? $e::class : null,
                'exception' => $e?->getMessage(),
                'file' => $e?->getFile(),
                'line' => $e?->getLine(),
                'state_keys' => array_keys($state->toArray()),
                'available_routes' => $this->getAvailableRoutes(),
            ]);
        }
    }

    protected function buildPrompt(StateContract $state): string
    {
        return "Choose the best route for the current state.\n\nAvailable routes:\n{$this->routesText()}\n\nCurrent state:\n".json_encode($state->toArray());
    }

    /**
     * Variables for $prompt: routes (one "- key: description" line each) and state (the state as JSON). Override to
     * add more, merging over these.
     *
     * @return array<string, mixed>
     */
    protected function routingVars(StateContract $state): array
    {
        return ['routes' => $this->routesText(), 'state' => json_encode($state->toArray())];
    }

    protected function routesText(): string
    {
        $routes = [];
        foreach ($this->routes as $route => $description) {
            $routes[] = "- {$route}: {$description}";
        }

        return implode("\n", $routes);
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
        return LlmExecutor::parseModel($value);
    }

    private function executor(): LlmExecutor
    {
        $maxTokens = config('agenticgraph.router.max_tokens');
        $reasoning = config('agenticgraph.router.reasoning');

        return new LlmExecutor(
            client: $this->prismClient(),
            cache: $this->cache,
            events: $this->events,
            nodeName: 'PrismRouter',
            cacheScope: static::class,
            model: $this->model,
            maxTokens: $this->maxTokens ?? (is_numeric($maxTokens) ? (int) $maxTokens : 1024),
            trackTokens: $this->trackTokens,
            reasoning: $this->reasoning ?? (is_string($reasoning) && $reasoning !== '' ? $reasoning : null),
        );
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
