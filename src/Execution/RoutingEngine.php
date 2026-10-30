<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\LoopAwareRouterInterface;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\RouterResolverInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\RouteDecided;
use Shahzad\AgenticGraph\Events\RouteFellBack;
use Shahzad\AgenticGraph\Exceptions\RoutingException;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Routers\PrismRouter;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceTime;

final class RoutingEngine implements RouterResolverInterface
{
    /** True once the current routing decision took a default route here. */
    private bool $fellBack = false;

    public function __construct(private readonly Container $container) {}

    /**
     * @return string|array<int, string>|null
     */
    public function determineNext(Graph $graph, string $currentNode, StateContract $state): string|array|null
    {
        $edges = $graph->getEdgesFrom($currentNode);
        foreach ($edges as $edge) {
            $condition = $this->resolveRouter($edge->getCondition());
            if ($condition !== null) {
                return $this->traced($currentNode, $condition, fn (): string|array => $this->checkedRoute($graph, $condition, $state));
            }
            $target = $edge->getTarget();

            return $target;
        }

        return null;
    }

    /**
     * @param  array<int, string>  $completedNodes
     */
    public function determinePostParallelNext(Graph $graph, array $completedNodes, StateContract $state): ?string
    {
        $sources = $completedNodes;
        sort($sources);
        $key = implode('|', $sources);

        return $this->traced($key, null, fn (): ?string => $this->joinTarget($graph, $key, $completedNodes, $state));
    }

    /**
     * @param  array<int, string>  $completedNodes
     */
    private function joinTarget(Graph $graph, string $key, array $completedNodes, StateContract $state): ?string
    {
        if (! empty($graph->joins) && array_key_exists($key, $graph->joins)) {
            return $graph->joins[$key];
        }

        $nextTargets = [];
        foreach ($completedNodes as $nodeName) {
            $edges = $graph->getEdgesFrom($nodeName);
            if (empty($edges)) {
                continue;
            }
            $edge = $edges[0];
            $condition = $this->resolveRouter($edge->getCondition());
            $target = $condition ? $this->checkedRoute($graph, $condition, $state) : $edge->getTarget();
            $targetName = is_array($target) ? ($target[0] ?? null) : $target;
            if ($targetName) {
                $nextTargets[] = $targetName;
            }
        }
        if (empty($nextTargets)) {
            return null;
        }
        $unique = array_values(array_unique($nextTargets));

        return $unique[0];
    }

    /**
     * Runs one routing decision inside a route span. The span's parent is the
     * root graph span, and a router's own LLM call nests under the route span.
     * A null router means a join.
     *
     * @template T of string|array<int, string>|null
     *
     * @param  \Closure(): T  $decide
     * @return T
     */
    private function traced(string $from, ?RouterInterface $router, \Closure $decide): string|array|null
    {
        $this->fellBack = false;
        $trace = TraceContext::tryGet();
        $spanId = $trace?->startSpan($from, 'route');
        if ($trace === null || $spanId === null) {
            return $decide();
        }

        $startedAt = TraceTime::now();
        try {
            $result = $decide();
        } finally {
            $trace->endSpan();
        }
        $endedAt = TraceTime::now();

        $targets = array_values((array) ($result ?? []));
        $flags = $router instanceof PrismRouter ? $router->lastRouteFlags() : ['from_cache' => false, 'fallback' => false];
        $kind = match (true) {
            $router === null => 'join',
            count($targets) > 1 => 'fan_out',
            $router instanceof PrismRouter => 'llm',
            default => 'deterministic',
        };

        $this->container->make(Dispatcher::class)->dispatch(new RouteDecided(
            executionId: $trace->getExecutionId(),
            spanId: $spanId,
            parentSpanId: $trace->getRootSpanId(),
            from: $from,
            targets: $targets,
            router: $router !== null ? $router::class : null,
            kind: $kind,
            fromCache: $flags['from_cache'],
            fallback: $flags['fallback'] || $this->fellBack,
            startedAt: $startedAt,
            endedAt: $endedAt,
        ));

        return $result;
    }

    /**
     * The router's decision, checked against the graph. An empty route, or one naming a node the graph does not have,
     * takes the router's default route (warning, RouteFellBack, route.fallback on the span). Throws RoutingException
     * when the default route is unset or not a node, and for a fan-out naming an unknown node.
     *
     * @return string|array<int, string>
     */
    private function checkedRoute(Graph $graph, RouterInterface $router, StateContract $state): string|array
    {
        $route = $this->resolveRoute($router, $state);
        $targets = (array) $route;
        $unknown = array_filter($targets, fn (string $target): bool => ! $graph->hasNode($target));
        if ($targets !== [] && $unknown === []) {
            return $route;
        }

        $reason = $targets === [] || $route === '' ? 'empty_route' : 'unknown_route';
        $default = $router->getDefaultRoute();
        if ((is_array($route) && $targets !== []) || $default === null || ! $graph->hasNode($default)) {
            throw new RoutingException($router::class, $reason, (string) json_encode($route));
        }

        $this->fellBack = true;
        if ((bool) config('agenticgraph.logging.enabled', true)) {
            $this->container->make(LoggerInterface::class)->warning('Router fell back to the default route', [
                'router' => $router::class,
                'default_route' => $default,
                'reason' => $reason,
            ]);
        }
        $this->container->make(Dispatcher::class)->dispatch(new RouteFellBack($router::class, $default, $reason));

        return $default;
    }

    /**
     * @return string|array<int, string>
     */
    public function resolveRoute(RouterInterface $router, StateContract $state): string|array
    {
        if ($router instanceof LoopAwareRouterInterface && $this->container->bound(ExecutionContext::class)) {
            $executionContext = $this->container->make(ExecutionContext::class);

            return $router->routeWithContext($state, $executionContext);
        }

        return $router->route($state);
    }

    public function resolveRouter(mixed $router): ?RouterInterface
    {
        if ($router === null) {
            return null;
        }

        if ($router instanceof RouterInterface) {
            return $router;
        }

        if (! is_string($router)) {
            throw new \RuntimeException('Router class must implement RouterInterface: '.get_debug_type($router));
        }

        $instance = $this->container->make($router);
        if (! $instance instanceof RouterInterface) {
            throw new \RuntimeException(
                "Router class must implement RouterInterface: {$router}"
            );
        }

        return $instance;
    }
}
