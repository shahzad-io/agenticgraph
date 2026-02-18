<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\Execution\ExecutionHandle;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatusResolver;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
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

    public function execute(string $name, StateContract $state, ExecutionOptions $options = new ExecutionOptions): ExecutionHandle
    {
        $graph = $this->registry()->getOrBuild($name);

        if ($graph === null) {
            throw new \RuntimeException(
                "Graph '{$name}' not found in registry or config. ".
                "Please register the graph manually or add it to config/agenticgraph.php 'graphs' array."
            );
        }

        return $this->executor()->executeWithOptions($graph, $state, $options);
    }

    public function getExecution(string $executionId): ExecutionHandle
    {
        $store = app(ExecutionStore::class);
        $resolver = app(ExecutionStatusResolver::class);
        $checkpoints = app(CheckpointManager::class);
        $record = $store->get($executionId);
        $options = new ExecutionOptions(ExecutionMode::ASYNC, StreamingMode::NONE);

        return new ExecutionHandle(
            executionId: $executionId,
            options: $options,
            executor: $this->executor(),
            store: $store,
            resolver: $resolver,
            checkpoints: $checkpoints,
            status: $record?->status
        );
    }

    public function resumeInterrupt(string $checkpointId, mixed $humanInput): StateContract
    {
        $state = $this->executor()->resumeWithInput($checkpointId, $humanInput);
        if ($state === null) {
            throw new \RuntimeException("Interrupt checkpoint {$checkpointId} not found");
        }

        return $state;
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
