<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Bus\Batch;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\LoopAwareRouterInterface;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Events\CycleCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionFailed;
use Shahzad\AgenticGraph\Events\NodeExecutionStarted;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Middleware\LoggingMiddleware;
use Shahzad\AgenticGraph\Middleware\TimingMiddleware;
use Shahzad\AgenticGraph\Middleware\ValidationMiddleware;
use Shahzad\AgenticGraph\State\StateManager;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;
use Shahzad\AgenticGraph\Storage\FileStorage;
use Shahzad\AgenticGraph\Storage\RedisStorage;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\Instrumentation\HttpClientInstrumentation;
use Shahzad\AgenticGraph\Tracing\TraceContext;

class GraphExecutor
{
    /**
     * @var array<int, class-string|object>
     */
    protected array $globalMiddleware;

    private Dispatcher $events;

    private CacheManager $cache;

    private BusDispatcher $bus;

    private LoggerInterface $logger;

    private ExecutionRepository $repo;

    private StateManager $stateManager;

    private LoopGuard $loopGuard;

    public function __construct(
        ?Dispatcher $events = null,
        ?CacheManager $cache = null,
        ?BusDispatcher $bus = null,
        ?LoggerInterface $logger = null,
        ?ExecutionRepository $repo = null,
        ?StateManager $stateManager = null,
    ) {
        $this->globalMiddleware = config('agenticgraph.middleware.global', [
            LoggingMiddleware::class,
            TimingMiddleware::class,
            ValidationMiddleware::class,
        ]);
        $this->events = $events ?? app(Dispatcher::class);
        $this->cache = $cache ?? app(CacheManager::class);
        $this->bus = $bus ?? (Bus::getFacadeRoot() ?? app(BusDispatcher::class));
        $this->logger = $logger ?? app(LoggerInterface::class);
        $this->repo = $repo ?? app(ExecutionRepository::class);
        $this->stateManager = $stateManager ?? app(StateManager::class);

        // Initialize loop guard from config
        $loopConfig = config('agenticgraph.execution.loop', []);
        $this->loopGuard = new LoopGuard(
            maxGlobalIterations: $loopConfig['max_iterations'] ?? 1000,
            maxCycleIterations: $loopConfig['max_cycle_iterations'] ?? null,
            timeoutSeconds: $loopConfig['timeout_seconds'] ?? null,
            enabled: $loopConfig['enabled'] ?? false,
        );
    }

    public function execute(Graph $graph, StateContract $state, bool $useBatch = false, ?string $executionId = null): StateContract
    {
        // Validate state before execution
        $state->validate();

        $executionId = $executionId ?? $this->generateExecutionId();
        $repo = $this->repo;

        // Reuse existing trace context if available (from async job), otherwise create new
        if (! app()->bound(TraceContext::class)) {
            $traceContext = new TraceContext(
                executionId: $executionId,
                rootSpanId: uniqid('span_', true)
            );
            app()->instance(TraceContext::class, $traceContext);
        } else {
            $traceContext = app(TraceContext::class);
        }

        // Reuse existing execution clock if available (from async job), otherwise create new
        if (! app()->bound(ExecutionClock::class)) {
            app()->instance(ExecutionClock::class, new ExecutionClock($executionId));
        }

        // Reuse existing execution context if available (from async job), otherwise create new
        if (! app()->bound(ExecutionContext::class)) {
            $executionContext = new ExecutionContext(
                executionId: $executionId,
                startTime: microtime(true),
            );
            app()->instance(ExecutionContext::class, $executionContext);
        } else {
            $executionContext = app(ExecutionContext::class);
        }

        // Ensure execution record exists and transition to running
        if (! $repo->get($executionId)) {
            $repo->queued($executionId, $graph->name, $state->toArray());
        }
        $repo->started($executionId, $graph->name, $state->toArray());
        $rootSpanId = $traceContext->getRootSpanId();
        $executionStartTime = microtime(true);
        $executionStartDt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $executionStartTime)) ?: new \DateTimeImmutable;
        $this->events->dispatch(new GraphExecutionStarted($executionId, $graph->name, $state, $executionStartDt, $rootSpanId));
        $current = $graph->entryPoint;
        $start = $executionStartTime;

        try {
            while (true) {
                $lockTimeout = (int) config('agenticgraph.lock.timeout', 10);
                $lockBlockTimeout = (int) config('agenticgraph.lock.block_timeout', 5);
                $lock = $this->cache->lock('agenticgraph:execution:'.$executionId, $lockTimeout);
                try {
                    if (! $lock->block($lockBlockTimeout)) {
                        throw new \RuntimeException(
                            "Failed to acquire lock for execution {$executionId} after {$lockBlockTimeout} seconds. ".
                            'Another process may be holding the lock.'
                        );
                    }

                    // Loop tracking: update path stack and detect cycles
                    $executionContext = $executionContext->pushNode($current);
                    [$executionContext, $cycle] = $executionContext->detectAndRecordCycle($current);

                    // Check loop guards (if enabled)
                    try {
                        $this->loopGuard->check($executionContext, $cycle);
                    } catch (LoopLimitExceededException $e) {
                        $this->repo->failed($executionId, $e->getMessage());
                        throw $e;
                    }

                    // Increment iteration and update container
                    $executionContext = $executionContext->incrementIteration();
                    app()->instance(ExecutionContext::class, $executionContext);

                    // Dispatch cycle completion event if a cycle was detected
                    if ($cycle !== null) {
                        $this->events->dispatch(new CycleCompleted(
                            executionId: $executionId,
                            cycleHash: $cycle->cycleHash,
                            cyclePath: $cycle->cyclePath,
                            completionCount: $cycle->completionCount,
                            globalIterations: $executionContext->globalIterations,
                            completedAt: new \DateTimeImmutable,
                            spanId: $traceContext->getSpanId(),
                        ));

                        if ((bool) config('agenticgraph.logging.enabled', true)) {
                            $this->logger->debug('Cycle completed', [
                                'execution_id' => $executionId,
                                'cycle_hash' => $cycle->cycleHash,
                                'cycle_path' => $cycle->cyclePath,
                                'completion_count' => $cycle->completionCount,
                                'global_iterations' => $executionContext->globalIterations,
                                'node_visit_count' => $executionContext->nodeVisitCounts[$current] ?? 0,
                            ]);
                        }
                    }

                    // Check for interrupt BEFORE executing node
                    $node = $graph->getNode($current);
                    if ($this->shouldInterrupt($node, $state)) {
                        // At this point we know $node is a PrismNode (checked in shouldInterrupt)
                        assert($node instanceof \Shahzad\AgenticGraph\Nodes\PrismNode);
                        $state = $this->handleInterrupt($graph, $executionId, $current, $state, $node);
                        $lock->release();
                        break; // Stop execution, return state with interrupt
                    }

                    $nodeResult = $this->executeNode($graph, $executionId, $current, $state);
                    $state = $nodeResult['state'];
                    $spanId = $nodeResult['spanId'];
                    $this->checkpoint($graph, $executionId, $current, $state, $executionContext);

                    $this->events->dispatch(new NodeExecutionCompleted(
                        $executionId,
                        $current,
                        $nodeResult['inputState'],
                        $state,
                        $nodeResult['duration'],
                        ['node_type' => $nodeResult['node']::class],
                        $spanId,
                        $nodeResult['startedAt'],
                        $nodeResult['parentSpanId'] ?? null
                    ));

                    // End span for this node
                    $traceContext->endSpan();

                    if (in_array($current, $graph->exitPoints, true)) {
                        break;
                    }
                    $next = $this->determineNext($graph, $current, $state);
                    if ($next === null) {
                        break;
                    }
                    if (is_array($next)) {
                        if ((bool) config('agenticgraph.logging.enabled', true)) {
                            $this->logger->debug('Parallel execution detected', [
                                'execution_id' => $executionId,
                                'current_node' => $current,
                                'next_nodes' => $next,
                                'use_batch' => $useBatch,
                            ]);
                        }
                        if ($useBatch) {
                            if ((bool) config('agenticgraph.logging.enabled', true)) {
                                $this->logger->debug('Dispatching parallel batch jobs', [
                                    'execution_id' => $executionId,
                                    'workers' => $next,
                                ]);
                            }
                            $this->executeParallelViaBatch($graph, $state, $executionId, $current, $next, $spanId);

                            app()->forgetInstance(TraceContext::class);
                            app()->forgetInstance(ExecutionContext::class);

                            return $state; // Async continuation, return current state
                        } else {
                            $state = $this->executeParallel($graph, $state, $executionId, $current, $next, $spanId);
                            $joinNext = $this->determinePostParallelNext($graph, $next, $state);
                            $current = $joinNext ?? ($graph->exitPoints[0] ?? $current);
                        }
                    } else {
                        $current = $next;
                    }
                } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                    $this->repo->failed($executionId, 'Lock timeout: '.$e->getMessage());
                    throw new \RuntimeException('Execution locked by another process', 0, $e);
                } finally {
                    optional($lock)->release();
                }
            }

            $duration = microtime(true) - $start;

            // Clear execution context after successful completion
            app()->forgetInstance(ExecutionContext::class);
            $this->repo->completed($executionId, $state->toArray(), $duration);
            $completedAt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true))) ?: new \DateTimeImmutable;
            $this->events->dispatch(new GraphExecutionCompleted($executionId, $graph->name, $state, $completedAt, $duration, [], $traceContext->getRootSpanId()));

            return $state;
        } catch (\Throwable $e) {
            $duration = microtime(true) - $start;
            $this->events->dispatch(new GraphExecutionFailed($executionId, $graph->name, $state, $e, $duration * 1000));
            throw $e;
        } finally {
            if (app()->bound(HttpClientInstrumentation::class)) {
                app(HttpClientInstrumentation::class)->cleanupExecution($executionId);
            }
            app()->forgetInstance(ExecutionClock::class);
            app()->forgetInstance(TraceContext::class);
            app()->forgetInstance(ExecutionContext::class);
        }
    }

    protected function runNodeWithMiddleware(StateContract $state, NodeInterface $node, string $executionId, string $nodeName, ?string $spanId): StateContract
    {
        $stack = [];
        foreach ($this->globalMiddleware as $mw) {
            $stack[] = is_string($mw) ? app($mw) : $mw;
        }
        $pipeline = array_reduce(
            array_reverse($stack),
            function ($next, $mw) use ($node) {
                return function (StateContract $state) use ($mw, $node, $next) {
                    return $mw->handle($state, $node, $next);
                };
            },
            function (StateContract $state) use ($node, $executionId, $nodeName, $spanId) {
                $attempts = 0;
                $max = $node->getRetryAttempts();
                $delay = $node->getRetryDelay();
                while (true) {
                    $attemptStart = microtime(true);
                    try {
                        $node->onBefore($state);
                        $result = $node->execute($state);
                        $node->onAfter($state, $result);

                        return $result;
                    } catch (\Throwable $e) {
                        $attempts++;
                        $durationMs = (microtime(true) - $attemptStart) * 1000;
                        $willRetry = $attempts <= $max && $node->shouldRetry($e);
                        $startedAt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $attemptStart)) ?: new \DateTimeImmutable;
                        $this->events->dispatch(new NodeExecutionFailed(
                            $executionId,
                            $spanId,
                            $nodeName,
                            $state,
                            $e,
                            $durationMs,
                            $attempts,
                            $willRetry,
                            $startedAt,
                            app(TraceContext::class)->getParentSpanId()
                        ));
                        $node->onError($e, $state);
                        if (! $willRetry) {
                            throw $e;
                        }
                        usleep($delay * 1000);
                    }
                }
            }
        );

        return $pipeline($state);
    }

    /**
     * Check if a node should interrupt execution.
     */
    private function shouldInterrupt(NodeInterface $node, StateContract $state): bool
    {
        // Only PrismNode supports interrupts
        if (! $node instanceof \Shahzad\AgenticGraph\Nodes\PrismNode) {
            return false;
        }

        return $node->shouldInterrupt($state);
    }

    /**
     * Handle node interrupt by creating checkpoint and pausing execution.
     */
    private function handleInterrupt(
        Graph $graph,
        string $executionId,
        string $nodeName,
        StateContract $state,
        \Shahzad\AgenticGraph\Nodes\PrismNode $node
    ): StateContract {
        // Get interrupt reason
        $reason = $node->getInterruptReason($state);

        // Create checkpoint FIRST (before modifying state)
        $checkpoint = new Checkpoint;
        $checkpoint->graphName = $graph->name;
        $checkpoint->executionId = $executionId;
        $checkpoint->state = $state; // State WITHOUT interrupt metadata yet
        $checkpoint->currentNode = $nodeName;
        $checkpoint->metadata['interrupted'] = true;
        $checkpoint->metadata['interrupt_reason'] = $reason;
        $checkpoint->createdAt = new \DateTimeImmutable;

        // Save checkpoint
        $checkpointId = app(CheckpointManager::class)->save($executionId, $checkpoint);

        // NOW add interrupt metadata to state (with checkpoint ID)
        $state = $state->setInterrupt($reason, $checkpointId);

        // Dispatch event
        $this->events->dispatch(new \Shahzad\AgenticGraph\Events\NodeInterrupted(
            executionId: $executionId,
            nodeName: $nodeName,
            reason: $reason,
            checkpointId: $checkpointId,
            stateSnapshot: $state->toArray(),
            interruptedAt: new \DateTimeImmutable
        ));

        // Log
        if ((bool) config('agenticgraph.logging.enabled', true)) {
            $this->logger->info('Execution interrupted', [
                'execution_id' => $executionId,
                'node' => $nodeName,
                'reason' => $reason,
                'checkpoint_id' => $checkpointId,
            ]);
        }

        return $state;
    }

    /**
     * @return string|array<int, string>|null
     */
    protected function determineNext(Graph $graph, string $current, StateContract $state): string|array|null
    {
        $edges = $graph->getEdgesFrom($current);
        foreach ($edges as $edge) {
            $condition = $edge->getCondition();
            if ($condition) {
                // Check if router is loop-aware and ExecutionContext is available
                if ($condition instanceof LoopAwareRouterInterface && app()->bound(ExecutionContext::class)) {
                    $executionContext = app(ExecutionContext::class);
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

    public function executeAsync(Graph $graph, StateContract $state): string
    {
        $state->validate();

        $executionId = $this->generateExecutionId();

        // Create trace context and capture rootSpanId for the job
        $traceContext = new TraceContext(
            executionId: $executionId,
            rootSpanId: uniqid('span_', true)
        );
        $rootSpanId = $traceContext->getRootSpanId();

        $executionContext = new ExecutionContext(
            executionId: $executionId,
            startTime: microtime(true),
        );

        // Bind to container temporarily for event dispatch
        app()->instance(TraceContext::class, $traceContext);
        app()->instance(ExecutionClock::class, new ExecutionClock($executionId));
        app()->instance(ExecutionContext::class, $executionContext);

        $this->repo->queued($executionId, $graph->name, $state->toArray());
        $this->repo->started($executionId, $graph->name, $state->toArray());
        $executionStartDt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true))) ?: new \DateTimeImmutable;
        $this->events->dispatch(new GraphExecutionStarted($executionId, $graph->name, $state, $executionStartDt, $rootSpanId));

        // Clean up container instances before dispatching job
        app()->forgetInstance(TraceContext::class);
        app()->forgetInstance(ExecutionClock::class);
        app()->forgetInstance(ExecutionContext::class);

        // Pass rootSpanId to job so it can maintain trace hierarchy
        dispatch(new \Shahzad\AgenticGraph\Jobs\ExecuteGraphJob(
            $graph->name,
            $executionId,
            $state->toArray(),
            checkpointId: null,
            rootSpanId: $rootSpanId
        ));

        return $executionId;
    }

    public function resume(string $checkpointId): ?StateContract
    {
        $storage = $this->resolveStorage();
        $checkpoint = $storage->load($checkpointId);
        if (! $checkpoint) {
            return null;
        }
        $graph = app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->get($checkpoint->graphName);
        if (! $graph) {
            return null;
        }

        $loopMetadata = null;
        if (isset($checkpoint->metadata['loop'])) {
            $loopMetadata = LoopMetadata::fromArray($checkpoint->metadata['loop']);
        }

        return $this->executeFromNode($graph, $checkpoint->state, $checkpoint->currentNode, $checkpoint->executionId, useBatch: true, loopMetadata: $loopMetadata);
    }

    /**
     * Resume execution from an interrupt checkpoint with human input.
     *
     * @param  string  $checkpointId  The checkpoint ID from the interrupt
     * @param  mixed  $humanInput  Human-provided input (typically array)
     * @return StateContract|null Final state or null if checkpoint not found
     *
     * @throws \InvalidArgumentException If checkpoint is not an interrupt checkpoint
     * @throws \RuntimeException If checkpoint or graph not found
     */
    public function resumeWithInput(string $checkpointId, mixed $humanInput): ?StateContract
    {
        $storage = $this->resolveStorage();
        $checkpoint = $storage->load($checkpointId);

        if ($checkpoint === null) {
            throw new \RuntimeException("Checkpoint {$checkpointId} not found");
        }

        // Validate this is an interrupt checkpoint
        if (! isset($checkpoint->metadata['interrupted']) ||
            $checkpoint->metadata['interrupted'] !== true) {
            throw new \InvalidArgumentException(
                "Checkpoint {$checkpointId} is not an interrupt checkpoint"
            );
        }

        // Get graph and node (auto-build from config if not cached)
        $graph = app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)
            ->getOrBuild($checkpoint->graphName);

        if ($graph === null) {
            throw new \RuntimeException("Graph {$checkpoint->graphName} not found in registry or config");
        }

        $node = $graph->getNode($checkpoint->currentNode);
        $state = $checkpoint->state;
        $executionId = $checkpoint->executionId;

        // Call node's onResume to process human input
        if ($node instanceof \Shahzad\AgenticGraph\Nodes\PrismNode) {
            $state = $node->onResume($state, $humanInput);
        }

        // Clear interrupt metadata
        $state = $state->clearInterrupt();

        // Dispatch resumed event
        $this->events->dispatch(new \Shahzad\AgenticGraph\Events\NodeResumed(
            executionId: $executionId,
            nodeName: $checkpoint->currentNode,
            checkpointId: $checkpointId,
            stateSnapshot: $state->toArray(),
            resumedAt: new \DateTimeImmutable
        ));

        // Log
        if ((bool) config('agenticgraph.logging.enabled', true)) {
            $this->logger->info('Execution resumed', [
                'execution_id' => $executionId,
                'checkpoint_id' => $checkpointId,
                'node' => $checkpoint->currentNode,
            ]);
        }

        // Continue execution from the interrupted node
        $loopMetadata = null;
        if (isset($checkpoint->metadata['loop'])) {
            $loopMetadata = LoopMetadata::fromArray($checkpoint->metadata['loop']);
        }

        return $this->executeFromNode(
            $graph,
            $state,
            $checkpoint->currentNode,
            $executionId,
            useBatch: false,
            loopMetadata: $loopMetadata
        );
    }

    /**
     * @param  array<int, string>  $targets
     */
    protected function executeParallel(Graph $graph, StateContract $state, string $executionId, string $sourceNode, array $targets, ?string $parentSpanId = null): StateContract
    {
        /**
         * @var array<int, StateContract> $results
         */
        $results = [];
        $traceContext = app(TraceContext::class);

        foreach ($targets as $nodeName) {
            $node = $graph->getNode($nodeName);
            $spanId = $traceContext->startSpan($nodeName);
            $inputState = $state;
            $nodeStartTime = microtime(true);
            $nodeStartDt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $nodeStartTime)) ?: new \DateTimeImmutable;
            $this->events->dispatch(new NodeExecutionStarted(
                $executionId, $nodeName, $state, $nodeStartDt,
                $spanId, $parentSpanId
            ));
            try {
                $resultState = $this->runNodeWithMiddleware($state, $node, $executionId, $nodeName, $spanId);
            } finally {
                $traceContext->endSpan();
            }
            $nodeDuration = microtime(true) - $nodeStartTime;
            $this->events->dispatch(new NodeExecutionCompleted(
                $executionId, $nodeName, $inputState, $resultState, $nodeDuration, ['node_type' => $node::class],
                $spanId
            ));
            $results[] = $resultState;
        }
        $merged = $this->stateManager->mergeAll($results);
        $this->checkpoint($graph, $executionId, $sourceNode, $merged);

        return $merged;
    }

    /**
     * @param  array<int, string>  $targets
     */
    protected function executeParallelViaBatch(Graph $graph, StateContract $state, string $executionId, string $sourceNode, array $targets, ?string $parentSpanId = null): void
    {
        /**
         * @var array<int, \Shahzad\AgenticGraph\Jobs\ExecuteNodeJob> $jobs
         */
        $jobs = [];

        foreach ($targets as $nodeName) {
            $jobs[] = new \Shahzad\AgenticGraph\Jobs\ExecuteNodeJob(
                $executionId,
                $nodeName,
                $state->toArray(),  // ✅ Pass array instead of object
                $graph->name,  // ✅ Pass graph name instead of object
                $parentSpanId
            );
        }

        Log::debug('Dispatching parallel batch jobs', [
            'execution_id' => $executionId,
            'node_count' => count($targets),
            'nodes' => $targets,
            'parent_span_id' => $parentSpanId,
        ]);

        $this->bus->batch($jobs)
            ->name("agenticgraph-parallel-{$executionId}")
            ->onConnection(config('agenticgraph.execution.queue_connection'))
            ->onQueue(config('agenticgraph.execution.queue_name'))
            ->allowFailures(false)  // ✅ Fail entire batch if one job fails
            ->then(function (Batch $batch) use ($graph, $executionId, $sourceNode, $targets) {
                $this->handleBatchCompletion($batch, $graph, $executionId, $sourceNode, $targets);
            })
            ->catch(function (Batch $batch, \Throwable $e) use ($executionId) {
                $this->handleBatchFailure($batch, $executionId, $e);
            })
            ->finally(function (Batch $batch) use ($executionId, $targets) {
                $this->cleanupBatchResults($executionId, $targets);
            })
            ->dispatch();
    }

    /**
     * Handle successful batch completion with retry logic for race conditions
     *
     * @param  array<int, string>  $targets
     */
    private function handleBatchCompletion(
        Batch $batch,
        Graph $graph,
        string $executionId,
        string $sourceNode,
        array $targets
    ): void {
        $cache = app(CacheManager::class);
        $results = [];
        $missing = [];

        // Collect all results with retry logic to handle race conditions
        foreach ($targets as $nodeName) {
            $key = 'agenticgraph:batch:'.$executionId.':'.$nodeName;

            // Retry up to 5 times with exponential backoff (100ms, 200ms, 400ms, 800ms, 1600ms)
            $resultData = null;
            $maxAttempts = 5;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $resultData = $cache->get($key);

                if ($resultData !== null) {
                    break;
                }

                // If not the last attempt, wait with exponential backoff
                if ($attempt < $maxAttempts) {
                    $delayMs = 100 * pow(2, $attempt - 1);
                    usleep($delayMs * 1000);
                }
            }

            if ($resultData === null) {
                $missing[] = $nodeName;

                continue;
            }

            $results[] = \Shahzad\AgenticGraph\State\State::fromArray($resultData);
        }

        // If any results are missing after retries, fail the batch
        if (! empty($missing)) {
            $errorMsg = sprintf(
                'Missing batch results for nodes: %s. Batch ID: %s, Execution: %s',
                implode(', ', $missing),
                $batch->id,
                $executionId
            );

            if ((bool) config('agenticgraph.logging.enabled', true)) {
                $this->logger->error('Batch completion failed - missing results', [
                    'execution_id' => $executionId,
                    'batch_id' => $batch->id,
                    'missing_nodes' => $missing,
                    'expected_nodes' => $targets,
                ]);
            }

            throw new \RuntimeException($errorMsg);
        }

        // Merge all results
        $merged = $this->stateManager->mergeAll($results);

        // Create checkpoint
        $this->checkpoint($graph, $executionId, $sourceNode, $merged);

        // Determine next node
        $next = $this->determinePostParallelNext($graph, $targets, $merged);

        // Get current execution context for loop metadata (if available)
        $loopMetadataArray = null;
        if (app()->bound(ExecutionContext::class)) {
            $executionContext = app(ExecutionContext::class);
            $loopMetadata = LoopMetadata::fromExecutionContext($executionContext);
            $loopMetadataArray = $loopMetadata->toArray();
        }

        // Continue execution
        dispatch(new \Shahzad\AgenticGraph\Jobs\ContinueExecutionJob(
            $graph->name,
            $executionId,
            $merged->toArray(),
            $next ?? ($graph->exitPoints[0] ?? $sourceNode),
            $loopMetadataArray
        ))->onConnection(config('agenticgraph.execution.queue_connection'))
            ->onQueue(config('agenticgraph.execution.queue_name'));
    }

    /**
     * Handle batch failure
     */
    private function handleBatchFailure(Batch $batch, string $executionId, \Throwable $e): void
    {
        $this->repo->failed(
            $executionId,
            "Batch execution failed: {$e->getMessage()}"
        );

        if ((bool) config('agenticgraph.logging.enabled', true)) {
            $this->logger->error('Batch execution failed', [
                'execution_id' => $executionId,
                'batch_id' => $batch->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Cleanup batch result cache entries
     *
     * @param  array<int, string>  $targets
     */
    private function cleanupBatchResults(string $executionId, array $targets): void
    {
        $cache = app(CacheManager::class);

        foreach ($targets as $nodeName) {
            $key = 'agenticgraph:batch:'.$executionId.':'.$nodeName;
            $cache->forget($key);
        }
    }

    /**
     * @param  array<int, string>  $parallelNodes
     */
    protected function determinePostParallelNext(Graph $graph, array $parallelNodes, StateContract $state): ?string
    {
        $sources = $parallelNodes;
        sort($sources);
        $key = implode('|', $sources);
        if (! empty($graph->joins) && array_key_exists($key, $graph->joins)) {
            return $graph->joins[$key];
        }
        /**
         * @var array<int, string> $nextTargets
         */
        $nextTargets = [];
        foreach ($parallelNodes as $nodeName) {
            $edges = $graph->getEdgesFrom($nodeName);
            if (empty($edges)) {
                continue;
            }
            $edge = $edges[0];
            $condition = $edge->getCondition();
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

    public function executeFromNode(Graph $graph, StateContract $state, string $startNode, string $executionId, bool $useBatch = false, ?LoopMetadata $loopMetadata = null): StateContract
    {
        // Create fresh trace context for this execution
        $traceContext = new TraceContext(
            executionId: $executionId,
            rootSpanId: uniqid('span_', true)
        );

        // Initialize ExecutionContext from metadata (resume) or fresh
        if ($loopMetadata !== null) {
            $executionContext = ExecutionContext::fromArray($loopMetadata->toArray(), $executionId);
        } else {
            $executionContext = new ExecutionContext(
                executionId: $executionId,
                startTime: microtime(true),
            );
        }

        // Bind to container for this execution scope
        app()->instance(TraceContext::class, $traceContext);
        app()->instance(ExecutionClock::class, new ExecutionClock($executionId));
        app()->instance(ExecutionContext::class, $executionContext);

        $current = $startNode;
        $visited = [];
        $maxIterations = config('agenticgraph.execution.max_iterations', 1000);
        $iterations = 0;
        $repo = $this->repo;

        try {
            do {
                $iterations++;
                if ($iterations > $maxIterations) {
                    throw new \RuntimeException(
                        "Graph execution exceeded maximum iterations ({$maxIterations}). ".
                        'Possible infinite loop detected. Visited nodes: '.implode(', ', $visited)
                    );
                }

                $visited[] = $current;

                $lockTimeout = (int) config('agenticgraph.lock.timeout', 10);
                $lockBlockTimeout = (int) config('agenticgraph.lock.block_timeout', 5);
                $lock = $this->cache->lock('agenticgraph:execution:'.$executionId, $lockTimeout);
                try {
                    if (! $lock->block($lockBlockTimeout)) {
                        throw new \RuntimeException("Failed to acquire lock for execution {$executionId}");
                    }

                    if ($repo->getStatus($executionId) === 'queued') {
                        $repo->started($executionId, $graph->name, $state->toArray());
                    }

                    $nodeResult = $this->executeNode($graph, $executionId, $current, $state);
                    $state = $nodeResult['state'];
                    $this->checkpoint($graph, $executionId, $current, $state);
                    $this->events->dispatch(new NodeExecutionCompleted(
                        $executionId,
                        $current,
                        $nodeResult['inputState'],
                        $state,
                        $nodeResult['duration'],
                        ['node_type' => $nodeResult['node']::class],
                        $nodeResult['spanId'],
                        $nodeResult['startedAt'],
                        $nodeResult['parentSpanId'] ?? null
                    ));
                    $traceContext->endSpan();

                    if (in_array($current, $graph->exitPoints, true)) {
                        break;
                    }

                    $next = $this->determineNext($graph, $current, $state);
                    if ($next === null) {
                        break;
                    }

                    if (is_array($next)) {
                        if ($useBatch) {
                            $this->executeParallelViaBatch($graph, $state, $executionId, $current, $next, $nodeResult['spanId']);

                            return $state;
                        } else {
                            $state = $this->executeParallel($graph, $state, $executionId, $current, $next, $nodeResult['spanId']);
                            $joinNext = $this->determinePostParallelNext($graph, $next, $state);
                            $current = $joinNext ?? ($graph->exitPoints[0] ?? $current);
                        }
                    } else {
                        $current = $next;
                    }
                } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                    $this->repo->failed($executionId, 'Lock timeout: '.$e->getMessage());
                    throw new \RuntimeException('Execution locked by another process', 0, $e);
                } finally {
                    optional($lock)->release();
                }
            } while (true);

            $repo->completed($executionId, $state->toArray(), 0.0);
            $completedAt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true))) ?: new \DateTimeImmutable;
            $this->events->dispatch(new GraphExecutionCompleted($executionId, $graph->name, $state, $completedAt, 0.0, []));

            return $state;
        } finally {
            if (app()->bound(HttpClientInstrumentation::class)) {
                app(HttpClientInstrumentation::class)->cleanupExecution($executionId);
            }
            app()->forgetInstance(ExecutionClock::class);
            app()->forgetInstance(TraceContext::class);
            app()->forgetInstance(ExecutionContext::class);
        }
    }

    /**
     * @return array{executionId: string, checkpointId: string|null}
     */
    public function executeUntil(Graph $graph, StateContract $state, callable $predicate): array
    {
        // Validate state before execution
        $state->validate();

        $executionId = $this->generateExecutionId();
        $repo = $this->repo;
        $repo->queued($executionId, $graph->name, $state->toArray());
        $repo->started($executionId, $graph->name, $state->toArray());

        // Create fresh trace context for this execution
        $traceContext = new TraceContext(
            executionId: $executionId,
            rootSpanId: uniqid('span_', true)
        );

        // Create execution context for loop tracking
        $executionContext = new ExecutionContext(
            executionId: $executionId,
            startTime: microtime(true),
        );

        // Bind to container for this execution scope
        app()->instance(TraceContext::class, $traceContext);
        app()->instance(ExecutionClock::class, new ExecutionClock($executionId));
        app()->instance(ExecutionContext::class, $executionContext);
        $current = $graph->entryPoint;
        $lastCheckpointId = null;
        try {
            while (! in_array($current, $graph->exitPoints, true)) {
                $lockTimeout = (int) config('agenticgraph.lock.timeout', 10);
                $lockBlockTimeout = (int) config('agenticgraph.lock.block_timeout', 5);
                $lock = $this->cache->lock('agenticgraph:execution:'.$executionId, $lockTimeout);
                try {
                    $lock->block($lockBlockTimeout);
                    $nodeResult = $this->executeNode($graph, $executionId, $current, $state);
                    $state = $nodeResult['state'];
                    $this->events->dispatch(new NodeExecutionCompleted(
                        $executionId,
                        $current,
                        $nodeResult['inputState'],
                        $state,
                        $nodeResult['duration'],
                        ['node_type' => $nodeResult['node']::class],
                        $nodeResult['spanId'],
                        $nodeResult['startedAt'],
                        $nodeResult['parentSpanId'] ?? null
                    ));
                    $traceContext->endSpan();
                    $next = $this->determineNext($graph, $current, $state);
                    if ($predicate($state)) {
                        $nextNode = is_array($next) ? ($next[0] ?? $current) : ($next ?? $current);
                        $checkpoint = new Checkpoint;
                        $checkpoint->graphName = $graph->name;
                        $checkpoint->executionId = $executionId;
                        $checkpoint->state = $state;
                        $checkpoint->currentNode = $nextNode;
                        $checkpoint->createdAt = new \DateTimeImmutable;
                        $lastCheckpointId = app(CheckpointManager::class)->save($executionId, $checkpoint);
                        break;
                    }
                    if ($next === null) {
                        break;
                    }
                    if (is_array($next)) {
                        $state = $this->executeParallel($graph, $state, $executionId, $current, $next, $nodeResult['spanId']);
                        $current = $this->determinePostParallelNext($graph, $next, $state) ?? ($graph->exitPoints[0] ?? $current);
                    } else {
                        $current = $next;
                    }
                } finally {
                    $lock->release();
                }
            }

            return ['executionId' => $executionId, 'checkpointId' => $lastCheckpointId];
        } finally {
            if (app()->bound(HttpClientInstrumentation::class)) {
                app(HttpClientInstrumentation::class)->cleanupExecution($executionId);
            }
            app()->forgetInstance(ExecutionClock::class);
            app()->forgetInstance(TraceContext::class);
            app()->forgetInstance(ExecutionContext::class);
        }
    }

    protected function checkpoint(Graph $graph, string $executionId, string $currentNode, StateContract $state, ?ExecutionContext $executionContext = null): void
    {
        if (! config('agenticgraph.checkpoint.enabled')) {
            return;
        }
        $checkpoint = new Checkpoint;
        $checkpoint->graphName = $graph->name;
        $checkpoint->executionId = $executionId;
        $checkpoint->state = $state;
        $checkpoint->currentNode = $currentNode;
        $checkpoint->createdAt = new \DateTimeImmutable;

        // Add loop metadata if context available
        if ($executionContext !== null) {
            $loopMetadata = LoopMetadata::fromExecutionContext($executionContext);
            $checkpoint->metadata['loop'] = $loopMetadata->toArray();
        }

        $checkpointManager = app(CheckpointManager::class);
        $checkpointManager->save($executionId, $checkpoint);
    }

    /**
     * @return array{
     *     state: StateContract,
     *     spanId: string|null,
     *     inputState: StateContract,
     *     node: NodeInterface,
     *     duration: float,
     *     startedAt: \DateTimeImmutable,
     *     parentSpanId: string|null
     * }
     */
    private function executeNode(Graph $graph, string $executionId, string $nodeName, StateContract $state, ?string $parentSpanId = null): array
    {
        $traceContext = app(TraceContext::class);
        $spanId = $traceContext->startSpan($nodeName);
        $effectiveParentSpanId = $parentSpanId ?? $traceContext->getParentSpanId();
        $node = $graph->getNode($nodeName);
        $nodeStartTime = microtime(true);
        $inputState = $state;

        $this->events->dispatch(new NodeExecutionStarted(
            $executionId,
            $nodeName,
            $state,
            new \DateTimeImmutable,
            $spanId,
            $effectiveParentSpanId
        ));

        $state = $this->runNodeWithMiddleware($state, $node, $executionId, $nodeName, $spanId);
        $nodeDuration = microtime(true) - $nodeStartTime;

        return [
            'state' => $state,
            'spanId' => $spanId,
            'inputState' => $inputState,
            'node' => $node,
            'duration' => $nodeDuration,
            'startedAt' => \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $nodeStartTime)) ?: new \DateTimeImmutable,
            'parentSpanId' => $effectiveParentSpanId,
        ];
    }

    private function resolveStorage(): StorageInterface
    {
        return match (config('agenticgraph.storage')) {
            'redis' => app(RedisStorage::class),
            'file' => app(FileStorage::class),
            default => app(DatabaseStorage::class),
        };
    }

    /**
     * Generate a validated execution ID.
     * Format: exec_{timestamp}_{random}
     */
    private function generateExecutionId(): string
    {
        $prefix = 'exec_';
        $timestamp = microtime(true);
        $random = bin2hex(random_bytes(8));

        return $prefix.$timestamp.'_'.$random;
    }
}
