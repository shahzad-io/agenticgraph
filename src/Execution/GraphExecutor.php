<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Bus\Batch;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
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
use Shahzad\AgenticGraph\Storage\DatabaseStorage;
use Shahzad\AgenticGraph\Storage\FileStorage;
use Shahzad\AgenticGraph\Storage\RedisStorage;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Psr\Log\LoggerInterface;

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

    public function __construct(
        ?Dispatcher $events = null,
        ?CacheManager $cache = null,
        ?BusDispatcher $bus = null,
        ?LoggerInterface $logger = null,
        ?ExecutionRepository $repo = null
    )
    {
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
    }

    public function execute(Graph $graph, StateContract $state, bool $useBatch = false, ?string $executionId = null): StateContract
    {
        $executionId = $executionId ?? uniqid('exec_', true);
        $repo = $this->repo;

        // Start tracing context
        TraceContext::startTrace($executionId);

        // Ensure execution record exists and transition to running
        if (! $repo->get($executionId)) {
            $repo->queued($executionId, $graph->name, $state->toArray());
        }
        $repo->started($executionId, $graph->name, $state->toArray());
        $this->events->dispatch(new GraphExecutionStarted($executionId, $graph->name, $state, new \DateTimeImmutable));
        $current = $graph->entryPoint;
        $start = microtime(true);

        try {
            while (true) {
                $lockTimeout = (int) config('agenticgraph.lock.timeout', 10);
                $lock = $this->cache->lock('agenticgraph:execution:'.$executionId, $lockTimeout);
                try {
                    if (! $lock->block(5)) {
                        throw new \RuntimeException(
                            "Failed to acquire lock for execution {$executionId} after 5 seconds. ".
                            'Another process may be holding the lock.'
                        );
                    }

                    $nodeResult = $this->executeNode($graph, $executionId, $current, $state);
                    $state = $nodeResult['state'];
                    $spanId = $nodeResult['spanId'];
                    $this->checkpoint($graph, $executionId, $current, $state);

                    $this->events->dispatch(new NodeExecutionCompleted(
                        $executionId,
                        $current,
                        $nodeResult['inputState'],
                        $state,
                        $nodeResult['duration'],
                        ['node_type' => $nodeResult['node']::class],
                        $spanId
                    ));

                    // End span for this node
                    TraceContext::endSpan();

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

                            TraceContext::endTrace();

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
            $this->repo->completed($executionId, $state->toArray(), $duration);
            $this->events->dispatch(new GraphExecutionCompleted($executionId, $graph->name, $state, new \DateTimeImmutable, $duration, []));

            return $state;
        } catch (\Throwable $e) {
            $duration = microtime(true) - $start;
            $this->events->dispatch(new GraphExecutionFailed($executionId, $graph->name, $state, $e, $duration * 1000));
            throw $e;
        } finally {
            TraceContext::endTrace();
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
                        $this->events->dispatch(new NodeExecutionFailed(
                            $executionId,
                            $spanId,
                            $nodeName,
                            $state,
                            $e,
                            $durationMs,
                            $attempts,
                            $willRetry
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

    protected function determineNext(Graph $graph, string $current, StateContract $state): string|array|null
    {
        $edges = $graph->getEdgesFrom($current);
        foreach ($edges as $edge) {
            $condition = $edge->getCondition();
            if ($condition) {
                $route = $condition->route($state);

                return $route;
            }
            $target = $edge->getTarget();

            return $target;
        }

        return null;
    }

    public function executeAsync(Graph $graph, StateContract $state): string
    {
        $executionId = uniqid('exec_', true);
        $this->repo->queued($executionId, $graph->name, $state->toArray());
        dispatch(new \Shahzad\AgenticGraph\Jobs\ExecuteGraphJob($graph->name, $executionId, $state));

        return $executionId;
    }

    public function resume(string $checkpointId): ?StateContract
    {
        $storage = $this->resolveStorage();
        $checkpoint = $storage->load($checkpointId);
        if (! $checkpoint) {
            return null;
        }
        $graph = \Shahzad\AgenticGraph\Graph\GraphRegistry::get($checkpoint->graphName);
        if (! $graph) {
            return null;
        }

        return $this->executeFromNode($graph, $checkpoint->state, $checkpoint->currentNode, $checkpoint->executionId, useBatch: true);
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
        foreach ($targets as $nodeName) {
            $node = $graph->getNode($nodeName);
            $spanId = TraceContext::startSpan($nodeName);
            $inputState = $state;
            $nodeStartTime = microtime(true);
            $this->events->dispatch(new NodeExecutionStarted(
                $executionId, $nodeName, $state, new \DateTimeImmutable,
                $spanId, $parentSpanId
            ));
            try {
                $resultState = $this->runNodeWithMiddleware($state, $node, $executionId, $nodeName, $spanId);
            } finally {
                TraceContext::endSpan();
            }
            $nodeDuration = microtime(true) - $nodeStartTime;
            $this->events->dispatch(new NodeExecutionCompleted(
                $executionId, $nodeName, $inputState, $resultState, $nodeDuration, ['node_type' => $node::class],
                $spanId
            ));
            $results[] = $resultState;
        }
        $merged = \Shahzad\AgenticGraph\State\StateManager::mergeAll($results);
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
            $jobs[] = new \Shahzad\AgenticGraph\Jobs\ExecuteNodeJob($executionId, $nodeName, $state, $graph, $parentSpanId);
        }
        $this->bus->batch($jobs)
            ->name("agenticgraph-parallel-{$executionId}")
            ->onConnection(config('agenticgraph.execution.queue_connection'))
            ->onQueue(config('agenticgraph.execution.queue_name'))
            ->then(function (Batch $batch) use ($graph, $executionId, $sourceNode, $targets) {
                $cache = app(CacheManager::class);
                $executor = app(self::class);
                $results = [];
                foreach ($targets as $nodeName) {
                    $key = 'agenticgraph:batch:'.$executionId.':'.$nodeName;
                    $resultData = $cache->get($key);
                    if (! $resultData) {
                        throw new \RuntimeException("Missing batch result for node: {$nodeName}");
                    }
                    $results[] = \Shahzad\AgenticGraph\State\State::fromArray($resultData);
                    $cache->forget($key);
                }
                $merged = \Shahzad\AgenticGraph\State\StateManager::mergeAll($results);
                $executor->checkpoint($graph, $executionId, $sourceNode, $merged);
                $next = $executor->determinePostParallelNext($graph, $targets, $merged);
                dispatch(new \Shahzad\AgenticGraph\Jobs\ContinueExecutionJob(
                    $graph->name,
                    $executionId,
                    $merged,
                    $next ?? ($graph->exitPoints[0] ?? $sourceNode)
                ))->onConnection(config('agenticgraph.execution.queue_connection'))
                    ->onQueue(config('agenticgraph.execution.queue_name'));
            })
            ->catch(function (Batch $batch, \Throwable $e) use ($executionId) {
                app(ExecutionRepository::class)->failed($executionId, $e->getMessage());
                throw $e;
            })
            ->dispatch();
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

    public function executeFromNode(Graph $graph, StateContract $state, string $startNode, string $executionId, bool $useBatch = false): StateContract
    {
        TraceContext::startTrace($executionId);
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
                $lock = $this->cache->lock('agenticgraph:execution:'.$executionId, $lockTimeout);
                try {
                    if (! $lock->block(5)) {
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
                        $nodeResult['spanId']
                    ));
                    TraceContext::endSpan();

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
            $this->events->dispatch(new GraphExecutionCompleted($executionId, $graph->name, $state, new \DateTimeImmutable, 0.0, []));

            return $state;
        } finally {
            TraceContext::endTrace();
        }
    }

    /**
     * @return array{executionId: string, checkpointId: string|null}
     */
    public function executeUntil(Graph $graph, StateContract $state, callable $predicate): array
    {
        $executionId = uniqid('exec_', true);
        $repo = $this->repo;
        $repo->queued($executionId, $graph->name, $state->toArray());
        $repo->started($executionId, $graph->name, $state->toArray());
        TraceContext::startTrace($executionId);
        $current = $graph->entryPoint;
        $lastCheckpointId = null;
        try {
            while (! in_array($current, $graph->exitPoints, true)) {
                $lock = $this->cache->lock('agenticgraph:execution:'.$executionId, (int) config('agenticgraph.lock.timeout'));
                try {
                    $lock->block(5);
                    $nodeResult = $this->executeNode($graph, $executionId, $current, $state);
                    $state = $nodeResult['state'];
                    $this->events->dispatch(new NodeExecutionCompleted(
                        $executionId,
                        $current,
                        $nodeResult['inputState'],
                        $state,
                        $nodeResult['duration'],
                        ['node_type' => $nodeResult['node']::class],
                        $nodeResult['spanId']
                    ));
                    TraceContext::endSpan();
                    $next = $this->determineNext($graph, $current, $state);
                    if ($predicate($state)) {
                        $nextNode = is_array($next) ? ($next[0] ?? $current) : ($next ?? $current);
                        $checkpoint = new Checkpoint;
                        $checkpoint->graphName = $graph->name;
                        $checkpoint->executionId = $executionId;
                        $checkpoint->state = $state;
                        $checkpoint->currentNode = $nextNode;
                        $checkpoint->createdAt = new \DateTimeImmutable;
                        $lastCheckpointId = (new CheckpointManager($this->resolveStorage()))->save($executionId, $checkpoint);
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
            TraceContext::endTrace();
        }
    }

    protected function checkpoint(Graph $graph, string $executionId, string $currentNode, StateContract $state): void
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
        (new CheckpointManager($this->resolveStorage()))->save($executionId, $checkpoint);
    }

    /**
     * @return array{
     *     state: StateContract,
     *     spanId: string|null,
     *     inputState: StateContract,
     *     node: NodeInterface,
     *     duration: float
     * }
     */
    private function executeNode(Graph $graph, string $executionId, string $nodeName, StateContract $state, ?string $parentSpanId = null): array
    {
        $spanId = TraceContext::startSpan($nodeName);
        $effectiveParentSpanId = $parentSpanId ?? TraceContext::getParentSpanId();
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
}
