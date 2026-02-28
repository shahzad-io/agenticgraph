<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Contracts\Container\Container;
use Shahzad\AgenticGraph\Contracts\LoopAwareRouterInterface;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\RouterResolverInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Graph\Graph;

final class RoutingEngine implements RouterResolverInterface
{
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
                if ($condition instanceof LoopAwareRouterInterface && $this->container->bound(ExecutionContext::class)) {
                    $executionContext = $this->container->make(ExecutionContext::class);
                    $route = $condition->routeWithContext($state, $executionContext);
                } else {
                    $route = $condition->route($state);
                }

                return $route;
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
            $target = $condition ? $condition->route($state) : $edge->getTarget();
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
