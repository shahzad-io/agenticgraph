<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

use Shahzad\AgenticGraph\Graph\Graph;

interface RouterResolverInterface
{
    /**
     * @return string|array<int, string>|null
     */
    public function determineNext(Graph $graph, string $currentNode, StateContract $state): string|array|null;

    /**
     * @param  array<int, string>  $completedNodes
     */
    public function determinePostParallelNext(Graph $graph, array $completedNodes, StateContract $state): ?string;

    public function resolveRouter(mixed $router): ?RouterInterface;
}
