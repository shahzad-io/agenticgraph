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
        return app(GraphRegistry::class);
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
        app(GraphRegistry::class)->register($name, $graph);
    }

    public function get(string $name): ?Graph
    {
        return app(GraphRegistry::class)->get($name);
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

    /**
     * Resume execution from an interrupt checkpoint with human input.
     *
     * @param  mixed  $humanInput  Human-provided input (typically array)
     */
    public function resumeWithInput(string $checkpointId, mixed $humanInput): ?StateContract
    {
        return $this->executor()->resumeWithInput($checkpointId, $humanInput);
    }

    /**
     * @return array{executionId: string, checkpointId: string|null}
     */
    public function executeUntil(Graph $graph, StateContract $state, callable $predicate): array
    {
        return $this->executor()->executeUntil($graph, $state, $predicate);
    }
}
