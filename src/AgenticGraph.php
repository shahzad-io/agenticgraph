<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;

class AgenticGraph
{
    public function registry(): GraphRegistry
    {
        return new GraphRegistry;
    }

    public function executor(): GraphExecutor
    {
        return app(GraphExecutor::class);
    }

    public function create(string $name): GraphBuilder
    {
        return GraphBuilder::create($name);
    }

    public function register(string $name, Graph $graph): void
    {
        GraphRegistry::register($name, $graph);
    }

    public function get(string $name): ?Graph
    {
        return GraphRegistry::get($name);
    }

    public function execute(Graph $graph, StateContract $state): StateContract
    {
        return $this->executor()->execute($graph, $state);
    }

    public function executeAsync(Graph $graph, StateContract $state): string
    {
        return $this->executor()->executeAsync($graph, $state);
    }

    public function resume(string $checkpointId): ?StateContract
    {
        return $this->executor()->resume($checkpointId);
    }

    public function executeUntil(Graph $graph, StateContract $state, callable $predicate): array
    {
        return $this->executor()->executeUntil($graph, $state, $predicate);
    }
}
