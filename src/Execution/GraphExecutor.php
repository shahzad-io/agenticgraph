<?php

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Bus;
use Illuminate\Bus\Batch;
use Shahzad\AgenticGraph\Contracts\EdgeInterface;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionStarted;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Middleware\LoggingMiddleware;
use Shahzad\AgenticGraph\Middleware\TimingMiddleware;
use Shahzad\AgenticGraph\Middleware\ValidationMiddleware;

class GraphExecutor
{
    /**
     * @var array<int, class-string|object>
     */
    protected array $globalMiddleware;

    public function __construct()
    {
        $this->globalMiddleware = config('agenticgraph.middleware.global', [
            LoggingMiddleware::class,
            TimingMiddleware::class,
            ValidationMiddleware::class,
        ]);
    }

    public function execute(Graph $graph, StateContract $state, bool $useBatch = false): StateContract
    {
        $executionId = uniqid('exec_', true);
        (new ExecutionRepository())->started($executionId, $graph->name, $state->toArray());
        Event::dispatch(new GraphExecutionStarted($executionId, $graph->name, $state, new \DateTimeImmutable()));
        $current = $graph->entryPoint;
        $start = microtime(true);
        while (true) {
            $lockTimeout = (int) config('agenticgraph.lock.timeout', 10);
            $lock = Cache::lock('agenticgraph:execution:' . $executionId, $lockTimeout);
            try {
                if (!$lock->block(5)) {
                    throw new \RuntimeException(
                        "Failed to acquire lock for execution {$executionId} after 5 seconds. " .
                        "Another process may be holding the lock."
                    );
                }
                $node = $graph->getNode($current);
                Event::dispatch(new NodeExecutionStarted($executionId, $current, $state, new \DateTimeImmutable()));
                $state = $this->runNodeWithMiddleware($state, $node);
                $this->checkpoint($graph, $executionId, $current, $state);
                Event::dispatch(new NodeExecutionCompleted($executionId, $current, $state, $state, 0.0, []));
                if (in_array($current, $graph->exitPoints, true)) {
                    break;
                }
                $next = $this->determineNext($graph, $current, $state);
                if ($next === null) {
                    break;
                }
                if (is_array($next)) {
                    if ($useBatch) {
                        $this->executeParallelViaBatch($graph, $state, $executionId, $current, $next);
                        return $state; // Async continuation, return current state
                    } else {
                        $state = $this->executeParallel($graph, $state, $executionId, $current, $next);
                        $joinNext = $this->determinePostParallelNext($graph, $next, $state);
                        $current = $joinNext ?? ($graph->exitPoints[0] ?? $current);
                    }
                } else {
                    $current = $next;
                }
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                (new ExecutionRepository())->failed($executionId, "Lock timeout: " . $e->getMessage());
                throw new \RuntimeException("Execution locked by another process", 0, $e);
            } finally {
                optional($lock)->release();
            }
        }
        $duration = microtime(true) - $start;
        (new ExecutionRepository())->completed($executionId, $state->toArray(), $duration);
        Event::dispatch(new GraphExecutionCompleted($executionId, $graph->name, $state, new \DateTimeImmutable(), $duration, []));
        return $state;
    }

    /**
     * @return StateContract
     */
    protected function runNodeWithMiddleware(StateContract $state, NodeInterface $node): StateContract
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
            function (StateContract $state) use ($node) {
                $attempts = 0;
                $max = $node->getRetryAttempts();
                $delay = $node->getRetryDelay();
                while (true) {
                    try {
                        $node->onBefore($state);
                        $result = $node->execute($state);
                        $node->onAfter($state, $result);
                        return $result;
                    } catch (\Throwable $e) {
                        $node->onError($e, $state);
                        $attempts++;
                        if ($attempts > $max || !$node->shouldRetry($e)) {
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
     * @return string|array|null
     */
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

    /**
     * @return string
     */
    public function executeAsync(Graph $graph, StateContract $state): string
    {
        $executionId = uniqid('exec_', true);
        (new ExecutionRepository())->queued($executionId, $graph->name, $state->toArray());
        dispatch(new \Shahzad\AgenticGraph\Jobs\ExecuteGraphJob($graph->name, $executionId, $state));
        return $executionId;
    }

    /**
     * @return StateContract|null
     */
    public function resume(string $checkpointId): ?StateContract
    {
        $storage = match (config('agenticgraph.storage')) {
            'redis' => app(\Shahzad\AgenticGraph\Storage\RedisStorage::class),
            'file' => app(\Shahzad\AgenticGraph\Storage\FileStorage::class),
            default => app(\Shahzad\AgenticGraph\Storage\DatabaseStorage::class),
        };
        $checkpoint = $storage->load($checkpointId);
        if (!$checkpoint) {
            return null;
        }
        $graph = \Shahzad\AgenticGraph\Graph\GraphRegistry::get($checkpoint->graphName);
        if (!$graph) {
            return null;
        }
        return $this->executeFromNode($graph, $checkpoint->state, $checkpoint->currentNode, $checkpoint->executionId, useBatch: true);
    }

    /**
     * @param array<int, string> $targets
     * @return StateContract
     */
    protected function executeParallel(Graph $graph, StateContract $state, string $executionId, string $sourceNode, array $targets): StateContract
    {
        /**
         * @var array<int, StateContract> $results
         */
        $results = [];
        foreach ($targets as $nodeName) {
            $node = $graph->getNode($nodeName);
            $results[] = $this->runNodeWithMiddleware($state, $node);
        }
        $merged = \Shahzad\AgenticGraph\State\StateManager::mergeAll($results);
        $this->checkpoint($graph, $executionId, $sourceNode, $merged);
        return $merged;
    }

    /**
     * @param array<int, string> $targets
     * @return void
     */
    protected function executeParallelViaBatch(Graph $graph, StateContract $state, string $executionId, string $sourceNode, array $targets): void
    {
        /**
         * @var array<int, \Shahzad\AgenticGraph\Jobs\ExecuteNodeJob> $jobs
         */
        $jobs = [];
        foreach ($targets as $nodeName) {
            $jobs[] = new \Shahzad\AgenticGraph\Jobs\ExecuteNodeJob($executionId, $nodeName, $state, $graph);
        }
        Bus::batch($jobs)
            ->name("agenticgraph-parallel-{$executionId}")
            ->then(function (Batch $batch) use ($graph, $executionId, $sourceNode, $targets) {
                $results = [];
                foreach ($targets as $nodeName) {
                    $key = 'agenticgraph:batch:' . $executionId . ':' . $nodeName;
                    $resultData = Cache::get($key);
                    if (!$resultData) {
                        throw new \RuntimeException("Missing batch result for node: {$nodeName}");
                    }
                    $results[] = \Shahzad\AgenticGraph\State\State::fromArray($resultData);
                    Cache::forget($key); // Clean up after reading
                }
                $merged = \Shahzad\AgenticGraph\State\StateManager::mergeAll($results);
                $this->checkpoint($graph, $executionId, $sourceNode, $merged);
                $next = $this->determinePostParallelNext($graph, $targets, $merged);
                dispatch(new \Shahzad\AgenticGraph\Jobs\ContinueExecutionJob(
                    $graph->name,
                    $executionId,
                    $merged,
                    $next ?? ($graph->exitPoints[0] ?? $sourceNode)
                ))->onConnection(config('agenticgraph.execution.queue_connection'))
                  ->onQueue(config('agenticgraph.execution.queue_name'));
            })
            ->catch(function (Batch $batch, \Throwable $e) use ($executionId) {
                (new ExecutionRepository())->failed($executionId, $e->getMessage());
                throw $e;
            })
            ->dispatch();
    }

    /**
     * @param array<int, string> $parallelNodes
     * @return string|null
     */
    protected function determinePostParallelNext(Graph $graph, array $parallelNodes, StateContract $state): ?string
    {
        $sources = $parallelNodes;
        sort($sources);
        $key = implode('|', $sources);
        if (!empty($graph->joins) && array_key_exists($key, $graph->joins)) {
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

    /**
     * @return StateContract
     */
    public function executeFromNode(Graph $graph, StateContract $state, string $startNode, string $executionId, bool $useBatch = false): StateContract
    {
        $current = $startNode;
        $visited = [];
        $maxIterations = config('agenticgraph.execution.max_iterations', 1000);
        $iterations = 0;

        do {
            $iterations++;
            if ($iterations > $maxIterations) {
                throw new \RuntimeException(
                    "Graph execution exceeded maximum iterations ({$maxIterations}). " .
                    "Possible infinite loop detected. Visited nodes: " . implode(', ', $visited)
                );
            }

            $visited[] = $current;

            $lockTimeout = (int) config('agenticgraph.lock.timeout', 10);
            $lock = Cache::lock('agenticgraph:execution:' . $executionId, $lockTimeout);
            try {
                if (!$lock->block(5)) {
                    throw new \RuntimeException("Failed to acquire lock for execution {$executionId}");
                }

                $node = $graph->getNode($current);
                Event::dispatch(new NodeExecutionStarted($executionId, $current, $state, new \DateTimeImmutable()));
                $state = $this->runNodeWithMiddleware($state, $node);
                $this->checkpoint($graph, $executionId, $current, $state);
                Event::dispatch(new NodeExecutionCompleted($executionId, $current, $state, $state, 0.0, []));

                // Check if we've reached an exit point AFTER executing the node
                if (in_array($current, $graph->exitPoints, true)) {
                    break;
                }

                $next = $this->determineNext($graph, $current, $state);
                if ($next === null) {
                    break;
                }

                if (is_array($next)) {
                    if ($useBatch) {
                        $this->executeParallelViaBatch($graph, $state, $executionId, $current, $next);
                        return $state; // Async continuation
                    } else {
                        $state = $this->executeParallel($graph, $state, $executionId, $current, $next);
                        $joinNext = $this->determinePostParallelNext($graph, $next, $state);
                        $current = $joinNext ?? ($graph->exitPoints[0] ?? $current);
                    }
                } else {
                    $current = $next;
                }
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                (new ExecutionRepository())->failed($executionId, "Lock timeout: " . $e->getMessage());
                throw new \RuntimeException("Execution locked by another process", 0, $e);
            } finally {
                optional($lock)->release();
            }
        } while (true);

        return $state;
    }

    /**
     * @return array{executionId: string, checkpointId: string|null}
     */
    public function executeUntil(Graph $graph, StateContract $state, callable $predicate): array
    {
        $executionId = uniqid('exec_', true);
        (new ExecutionRepository())->started($executionId, $graph->name, $state->toArray());
        $current = $graph->entryPoint;
        $lastCheckpointId = null;
        while (!in_array($current, $graph->exitPoints, true)) {
            $lock = Cache::lock('agenticgraph:execution:' . $executionId, (int) config('agenticgraph.lock.timeout'));
            try {
                $lock->block(5);
                $node = $graph->getNode($current);
                Event::dispatch(new NodeExecutionStarted($executionId, $current, $state, new \DateTimeImmutable()));
                $state = $this->runNodeWithMiddleware($state, $node);
                Event::dispatch(new NodeExecutionCompleted($executionId, $current, $state, $state, 0.0, []));
                $next = $this->determineNext($graph, $current, $state);
                if ($predicate($state)) {
                    $nextNode = is_array($next) ? ($next[0] ?? $current) : ($next ?? $current);
                    $checkpoint = new Checkpoint();
                    $checkpoint->graphName = $graph->name;
                    $checkpoint->executionId = $executionId;
                    $checkpoint->state = $state;
                    $checkpoint->currentNode = $nextNode;
                    $checkpoint->createdAt = new \DateTimeImmutable();
                    $storage = match (config('agenticgraph.storage')) {
                        'redis' => app(\Shahzad\AgenticGraph\Storage\RedisStorage::class),
                        'file' => app(\Shahzad\AgenticGraph\Storage\FileStorage::class),
                        default => app(\Shahzad\AgenticGraph\Storage\DatabaseStorage::class),
                    };
                    $lastCheckpointId = (new CheckpointManager($storage))->save($executionId, $checkpoint);
                    break;
                }
                if ($next === null) {
                    break;
                }
                if (is_array($next)) {
                    $state = $this->executeParallel($graph, $state, $executionId, $current, $next);
                    $current = $this->determinePostParallelNext($graph, $next, $state) ?? ($graph->exitPoints[0] ?? $current);
                } else {
                    $current = $next;
                }
            } finally {
                $lock->release();
            }
        }
        return ['executionId' => $executionId, 'checkpointId' => $lastCheckpointId];
    }

    protected function checkpoint(Graph $graph, string $executionId, string $currentNode, StateContract $state): void
    {
        if (!config('agenticgraph.checkpoint.enabled')) {
            return;
        }
        $checkpoint = new Checkpoint();
        $checkpoint->graphName = $graph->name;
        $checkpoint->executionId = $executionId;
        $checkpoint->state = $state;
        $checkpoint->currentNode = $currentNode;
        $checkpoint->createdAt = new \DateTimeImmutable();
        $storage = match (config('agenticgraph.storage')) {
            'redis' => app(\Shahzad\AgenticGraph\Storage\RedisStorage::class),
            'file' => app(\Shahzad\AgenticGraph\Storage\FileStorage::class),
            default => app(\Shahzad\AgenticGraph\Storage\DatabaseStorage::class),
        };
        (new CheckpointManager($storage))->save($executionId, $checkpoint);
    }
}
