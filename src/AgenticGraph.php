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

    /**
     * Execute a workflow by name (auto-builds from config if not cached).
     *
     * This method automatically retrieves or builds the graph from config,
     * eliminating the need for manual registration before execution.
     *
     * @param  string  $name  The graph name (as defined in config/agenticgraph.php)
     * @param  StateContract  $state  The initial state for execution
     * @return StateContract The final state after execution
     *
     * @throws \RuntimeException If the graph is not found in registry or config
     *
     * @example
     * $result = AgenticGraph::executeWorkflow('content-moderation', $state);
     * if ($result->hasInterrupt()) {
     *     // Handle interrupt for human review
     * }
     */
    public function executeWorkflow(string $name, StateContract $state): StateContract
    {
        $graph = $this->registry()->getOrBuild($name);

        if ($graph === null) {
            throw new \RuntimeException(
                "Graph '{$name}' not found in registry or config. ".
                "Please register the graph manually or add it to config/agenticgraph.php 'graphs' array."
            );
        }

        return $this->execute($graph, $state);
    }

    /**
     * Execute a workflow asynchronously by name (auto-builds from config if not cached).
     *
     * This method automatically retrieves or builds the graph from config,
     * then dispatches it to the queue for async execution.
     *
     * @param  string  $name  The graph name (as defined in config/agenticgraph.php)
     * @param  StateContract  $state  The initial state for execution
     * @return string The execution ID for tracking
     *
     * @throws \RuntimeException If the graph is not found in registry or config
     *
     * @example
     * $executionId = AgenticGraph::executeAsyncWorkflow('content-moderation', $state);
     * // Job will auto-build graph from config when processed
     */
    public function executeAsyncWorkflow(string $name, StateContract $state): string
    {
        $graph = $this->registry()->getOrBuild($name);

        if ($graph === null) {
            throw new \RuntimeException(
                "Graph '{$name}' not found in registry or config. ".
                "Please register the graph manually or add it to config/agenticgraph.php 'graphs' array."
            );
        }

        return $this->executeAsync($graph, $state);
    }

    /**
     * Resume a workflow from an interrupt checkpoint (auto-builds graph if needed).
     *
     * This is a convenience alias for resumeWithInput() with clearer naming.
     * The graph will be automatically rebuilt from config if not found in cache.
     *
     * @param  string  $checkpointId  The checkpoint ID from the interrupt
     * @param  mixed  $humanInput  Human-provided input (typically array with approved/comments)
     * @return StateContract|null The final state after resuming, or null if checkpoint not found
     *
     * @throws \InvalidArgumentException If checkpoint is not an interrupt checkpoint
     * @throws \RuntimeException If checkpoint or graph not found
     *
     * @example
     * $result = AgenticGraph::resumeWorkflow($checkpointId, [
     *     'approved' => true,
     *     'comments' => 'Looks good!'
     * ]);
     */
    public function resumeWorkflow(string $checkpointId, mixed $humanInput): ?StateContract
    {
        return $this->resumeWithInput($checkpointId, $humanInput);
    }

    /**
     * Get or build a graph by name (auto-builds from config if not cached).
     *
     * @param  string  $name  The graph name
     * @return Graph|null The graph instance, or null if not found
     */
    public function getOrBuild(string $name): ?Graph
    {
        return $this->registry()->getOrBuild($name);
    }
}
