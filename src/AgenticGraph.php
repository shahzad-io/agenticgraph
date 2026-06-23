<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Exceptions\InterruptPersistenceDisabledException;
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\Execution\ExecutionHandle;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\ExecutionStatusResolver;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\InterruptStatus;
use Shahzad\AgenticGraph\Execution\ResumeResult;
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

    public function run(string $name, StateContract $state): ExecutionHandle
    {
        return $this->execute($name, $state, new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::NONE,
        ));
    }

    public function dispatch(string $name, StateContract $state): ExecutionHandle
    {
        return $this->execute($name, $state, new ExecutionOptions(
            mode: ExecutionMode::ASYNC,
            streaming: StreamingMode::NONE,
        ));
    }

    /**
     * @param  list<string>|null  $nodes
     * @param  list<string>|null  $events
     */
    public function stream(
        string $name,
        StateContract $state,
        ?array $nodes = null,
        ?array $events = null,
        bool $inline = false,
    ): ExecutionHandle {
        $options = new ExecutionOptions(
            mode: $inline ? ExecutionMode::SYNC : ExecutionMode::ASYNC,
            streaming: $inline ? StreamingMode::ITERATOR : StreamingMode::SSE,
        );

        if ($nodes !== null) {
            $options = $options->withNodeFilter($nodes);
        }
        if ($events !== null) {
            $options = $options->withEventTypeFilter($events);
        }

        return $this->execute($name, $state, $options);
    }

    /**
     * @param  list<string>|null  $nodes
     * @param  list<string>|null  $events
     */
    public function broadcast(
        string $name,
        StateContract $state,
        ?array $nodes = null,
        ?array $events = null,
        bool $async = true,
    ): ExecutionHandle {
        $options = new ExecutionOptions(
            mode: $async ? ExecutionMode::ASYNC : ExecutionMode::SYNC,
            streaming: StreamingMode::BROADCAST,
        );

        if ($nodes !== null) {
            $options = $options->withNodeFilter($nodes);
        }
        if ($events !== null) {
            $options = $options->withEventTypeFilter($events);
        }

        return $this->execute($name, $state, $options);
    }

    public function getExecution(string $executionId): ExecutionHandle
    {
        $store = app(ExecutionStore::class);
        $resolver = app(ExecutionStatusResolver::class);
        $checkpoints = app(CheckpointManager::class);
        $record = $store->get($executionId);
        $options = $record->executionOptions ?? new ExecutionOptions;

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

    public function resume(string $executionId, mixed $humanInput): ResumeResult
    {
        if (! (bool) config('agenticgraph.interrupts.persistence_enabled', true)) {
            throw new InterruptPersistenceDisabledException;
        }

        $handle = $this->getExecution($executionId)->refresh();
        $streamId = $this->resolveResumeStreamId($executionId);

        $pending = $handle->pendingInterrupt();

        if ($pending === null) {
            $resolved = InterruptRecord::query()
                ->forExecution($executionId)
                ->resolved()
                ->latest('id')
                ->first();
            if ($resolved) {
                $metadata = $resolved->metadata();
                $candidate = $metadata['stream_id'] ?? null;
                if (is_string($candidate) && $candidate !== '') {
                    $streamId = $candidate;
                }
            }

            return new ResumeResult(
                handle: $handle,
                interrupt: $resolved,
                alreadyResolved: $resolved !== null,
                streamId: $streamId
            );
        }

        $resumed = $handle->resume($humanInput);

        $interrupt = InterruptRecord::query()
            ->forExecution($executionId)
            ->whereIn('status', ['pending', 'resolving'])
            ->latest('id')
            ->first();

        return new ResumeResult(
            handle: $resumed,
            interrupt: $interrupt ?? $pending,
            alreadyResolved: false,
            streamId: $streamId ?? $resumed->streamId()
        );
    }

    private function resolveResumeStreamId(string $executionId): ?string
    {
        $record = app(ExecutionStore::class)->get($executionId);
        $streamId = $record?->streamId;
        $interrupt = InterruptRecord::query()
            ->forExecution($executionId)
            ->pending()
            ->latest('id')
            ->first();

        if ($interrupt) {
            $metadata = $interrupt->metadata();
            $candidate = $metadata['stream_id'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                $streamId = $candidate;
            }
        }

        return $streamId;
    }

    public function interruptStatus(string $executionId): InterruptStatus
    {
        $handle = $this->getExecution($executionId)->refresh();
        $interrupt = $handle->pendingInterrupt();
        $record = app(ExecutionStore::class)->get($executionId);
        $streamId = $record?->streamId;
        $status = $handle->status();

        if ($interrupt) {
            $metadata = $interrupt->metadata();
            $candidate = $metadata['stream_id'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                $streamId = $candidate;
            }
            $status = ExecutionStatus::INTERRUPTED;
        }

        return new InterruptStatus(
            status: $status,
            interrupt: $interrupt,
            streamId: $streamId,
            latestState: $handle->latestState()
        );
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
