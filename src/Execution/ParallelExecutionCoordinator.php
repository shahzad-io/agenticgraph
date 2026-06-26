<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Bus\Batch;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Bus\PendingDispatch;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\ParallelExecutorInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionFailed;
use Shahzad\AgenticGraph\Events\NodeExecutionStarted;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Middleware\LoggingMiddleware;
use Shahzad\AgenticGraph\Middleware\TimingMiddleware;
use Shahzad\AgenticGraph\Middleware\ValidationMiddleware;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\State\StateManager;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamLifecycleEventEmitter;
use Shahzad\AgenticGraph\Streaming\StreamLifecycleEventTypes;
use Shahzad\AgenticGraph\Streaming\StreamNodeCompletePayloadBuilder;
use Shahzad\AgenticGraph\Tracing\TraceContext;

final class ParallelExecutionCoordinator implements ParallelExecutorInterface
{
    /**
     * @var array<int, class-string|object>
     */
    private array $globalMiddleware;

    /**
     * @param  array<int, class-string|object>|null  $globalMiddleware
     * @param  \Closure(): ?TraceContext  $traceContextProvider
     * @param  \Closure(): ?ExecutionContext  $executionContextProvider
     * @param  \Closure(object): PendingDispatch  $dispatchCallable
     */
    public function __construct(
        private readonly RoutingEngine $routingEngine,
        private readonly StateManager $stateManager,
        private readonly CacheManager $cache,
        private readonly BusDispatcher $bus,
        private readonly LoggerInterface $logger,
        private readonly ExecutionRepository $repo,
        private readonly Dispatcher $events,
        private readonly Container $container,
        private readonly CheckpointManager $checkpoints,
        ?array $globalMiddleware,
        private readonly \Closure $traceContextProvider,
        private readonly \Closure $executionContextProvider,
        private readonly \Closure $dispatchCallable
    ) {
        $this->globalMiddleware = $globalMiddleware ?? config('agenticgraph.middleware.global', [
            LoggingMiddleware::class,
            TimingMiddleware::class,
            ValidationMiddleware::class,
        ]);
    }

    /**
     * @param  array<int, string>  $parallelNodes
     */
    public function executeParallel(
        Graph $graph,
        array $parallelNodes,
        StateContract $state,
        string $executionId,
        ?StreamContext $streamContext = null
    ): ParallelResult {
        $results = [];
        $traceContext = ($this->traceContextProvider)();
        $parentSpanId = $traceContext instanceof TraceContext ? $traceContext->getSpanId() : null;
        $nodeCompletePayloadBuilder = StreamNodeCompletePayloadBuilder::fromConfig($this->stateManager);
        foreach ($parallelNodes as $nodeName) {
            $node = $graph->getNode($nodeName);
            $spanId = $traceContext instanceof TraceContext ? $traceContext->startSpan($nodeName) : null;
            $inputState = $state;
            $nodeStartTime = microtime(true);
            $nodeStartDt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $nodeStartTime)) ?: new \DateTimeImmutable;
            $this->events->dispatch(new NodeExecutionStarted(
                $executionId,
                $nodeName,
                $state,
                $nodeStartDt,
                $spanId,
                $parentSpanId
            ));
            $streamContext?->resetNodeSeq($nodeName);
            $this->emitStreamLifecycleIfActive(
                $streamContext,
                $nodeName,
                $spanId,
                $parentSpanId,
                StreamLifecycleEventTypes::NODE_START,
                ['node_name' => $nodeName]
            );
            try {
                $resultState = $this->runNodeWithMiddleware($state, $node, $executionId, $nodeName, $spanId);
            } finally {
                if ($traceContext instanceof TraceContext) {
                    $traceContext->endSpan();
                }
            }
            $nodeDuration = microtime(true) - $nodeStartTime;
            $this->events->dispatch(new NodeExecutionCompleted(
                $executionId,
                $nodeName,
                $inputState,
                $resultState,
                $nodeDuration,
                ['node_type' => $node::class],
                $spanId
            ));
            $this->emitStreamLifecycleIfActive(
                $streamContext,
                $nodeName,
                $spanId,
                $parentSpanId,
                StreamLifecycleEventTypes::NODE_COMPLETE,
                $nodeCompletePayloadBuilder->build($nodeName, $nodeDuration * 1000, $inputState, $resultState)
            );
            $results[] = $resultState;
        }
        $this->stateManager->warnParallelMergeCollisions($this->logger, $state, $parallelNodes, $results);
        $merged = $this->stateManager->mergeAll($results);
        $sourceNode = $this->resolveSourceNode($graph, $parallelNodes);
        $this->checkpoint($graph, $executionId, $sourceNode, $merged, $streamContext);
        $next = $this->routingEngine->determinePostParallelNext($graph, $parallelNodes, $merged);
        $fallback = $next ?? ($graph->exitPoints[0] ?? $sourceNode);

        return new ParallelResult($merged, $fallback, false);
    }

    /**
     * @param  array<int, string>  $parallelNodes
     */
    public function executeParallelViaBatch(
        Graph $graph,
        array $parallelNodes,
        StateContract $state,
        string $executionId
    ): ParallelResult {
        $jobs = [];
        $traceContext = ($this->traceContextProvider)();
        $parentSpanId = $traceContext instanceof TraceContext ? $traceContext->getSpanId() : null;
        foreach ($parallelNodes as $nodeName) {
            $jobs[] = new ExecuteNodeJob(
                $executionId,
                $nodeName,
                $state->toArray(),
                $graph->name,
                $parentSpanId
            );
        }

        $this->logger->debug('Dispatching parallel batch jobs', [
            'execution_id' => $executionId,
            'node_count' => count($parallelNodes),
            'nodes' => $parallelNodes,
            'parent_span_id' => $parentSpanId,
        ]);

        $sourceNode = $this->resolveSourceNode($graph, $parallelNodes);
        $loopMetadataArray = $this->captureLoopMetadataArray();
        $this->cache->put(
            $this->batchInputCacheKey($executionId),
            $state->toArray(),
            300
        );
        $callbacks = $this->buildBatchCallbacks(
            $graph->name,
            $executionId,
            $sourceNode,
            $parallelNodes,
            $loopMetadataArray
        );

        $this->bus->batch($jobs)
            ->name("agenticgraph-parallel-{$executionId}")
            ->onConnection(config('agenticgraph.execution.queue_connection'))
            ->onQueue(config('agenticgraph.execution.queue_name'))
            ->allowFailures(false)
            ->then($callbacks['then'])
            ->catch($callbacks['catch'])
            ->finally($callbacks['finally'])
            ->dispatch();

        return new ParallelResult($state, $sourceNode, true);
    }

    /**
     * @param  array<int, string>  $parallelNodes
     * @param  array<string, mixed>|null  $loopMetadataArray
     * @return array{
     *     then: \Closure(Batch): void,
     *     catch: \Closure(Batch, \Throwable): void,
     *     finally: \Closure(Batch): void
     * }
     */
    public function buildBatchCallbacks(
        string $graphName,
        string $executionId,
        string $sourceNode,
        array $parallelNodes,
        ?array $loopMetadataArray = null,
    ): array {
        return [
            'then' => static function (Batch $batch) use ($graphName, $executionId, $sourceNode, $parallelNodes, $loopMetadataArray): void {
                app(ParallelExecutionCoordinator::class)->handleBatchCompletion(
                    $batch,
                    $graphName,
                    $executionId,
                    $sourceNode,
                    $parallelNodes,
                    $loopMetadataArray
                );
            },
            'catch' => static function (Batch $batch, \Throwable $e) use ($executionId): void {
                app(ParallelExecutionCoordinator::class)->handleBatchFailure($batch, $executionId, $e);
            },
            'finally' => static function (Batch $batch) use ($executionId, $parallelNodes): void {
                app(ParallelExecutionCoordinator::class)->cleanupBatchResults($executionId, $parallelNodes);
            },
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function captureLoopMetadataArray(): ?array
    {
        $executionContext = ($this->executionContextProvider)();
        if ($executionContext instanceof ExecutionContext) {
            return LoopMetadata::fromExecutionContext($executionContext)->toArray();
        }

        return null;
    }

    /**
     * @param  array<int, string>  $targets
     * @param  array<string, mixed>|null  $loopMetadataArray
     */
    public function handleBatchCompletion(
        Batch $batch,
        string $graphName,
        string $executionId,
        string $sourceNode,
        array $targets,
        ?array $loopMetadataArray = null,
    ): void {
        $graph = app(GraphRegistry::class)->get($graphName);
        if ($graph === null) {
            throw new \RuntimeException("Graph not found: {$graphName}");
        }

        $results = [];
        $missing = [];

        foreach ($targets as $nodeName) {
            $key = $this->batchResultCacheKey($executionId, $nodeName);
            $resultData = null;
            $maxAttempts = 5;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $resultData = $this->cache->get($key);

                if ($resultData !== null) {
                    break;
                }

                if ($attempt < $maxAttempts) {
                    $delayMs = 100 * pow(2, $attempt - 1);
                    usleep($delayMs * 1000);
                }
            }

            if ($resultData === null) {
                $missing[] = $nodeName;

                continue;
            }

            $results[] = State::fromArray($resultData);
        }

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

        $inputData = $this->cache->get($this->batchInputCacheKey($executionId));
        $baseState = is_array($inputData) ? State::fromArray($inputData) : new State;
        $this->stateManager->warnParallelMergeCollisions($this->logger, $baseState, $targets, $results);
        $merged = $this->stateManager->mergeAll($results);
        $this->checkpoint($graph, $executionId, $sourceNode, $merged, null);
        $next = $this->routingEngine->determinePostParallelNext($graph, $targets, $merged);

        $job = new ContinueExecutionJob(
            $graphName,
            $executionId,
            $merged->toArray(),
            $next ?? ($graph->exitPoints[0] ?? $sourceNode),
            $loopMetadataArray
        );

        ($this->dispatchCallable)($job)
            ->onConnection(config('agenticgraph.execution.queue_connection'))
            ->onQueue(config('agenticgraph.execution.queue_name'));
    }

    public function handleBatchFailure(Batch $batch, string $executionId, \Throwable $e): void
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
     * @param  array<int, string>  $targets
     */
    public function cleanupBatchResults(string $executionId, array $targets): void
    {
        $this->cache->forget($this->batchInputCacheKey($executionId));

        foreach ($targets as $nodeName) {
            $key = $this->batchResultCacheKey($executionId, $nodeName);
            $this->cache->forget($key);
        }
    }

    private function batchInputCacheKey(string $executionId): string
    {
        return 'agenticgraph:batch:'.$executionId.':input';
    }

    private function batchResultCacheKey(string $executionId, string $nodeName): string
    {
        return 'agenticgraph:batch:'.$executionId.':'.$nodeName;
    }

    /**
     * @param  array<int, string>  $parallelNodes
     */
    private function resolveSourceNode(Graph $graph, array $parallelNodes): string
    {
        $executionContext = ($this->executionContextProvider)();
        if ($executionContext instanceof ExecutionContext && $executionContext->pathStack !== []) {
            $lastIndex = array_key_last($executionContext->pathStack);

            return $executionContext->pathStack[$lastIndex];
        }

        return $graph->entryPoint ?? ($parallelNodes[0] ?? '');
    }

    private function checkpoint(
        Graph $graph,
        string $executionId,
        string $currentNode,
        StateContract $state,
        ?StreamContext $streamContext
    ): void {
        if (! config('agenticgraph.checkpoint.enabled')) {
            return;
        }
        $checkpoint = new Checkpoint;
        $checkpoint->graphName = $graph->name;
        $checkpoint->executionId = $executionId;
        $checkpoint->state = $state;
        $checkpoint->currentNode = $currentNode;
        $checkpoint->createdAt = new \DateTimeImmutable;

        if ($streamContext) {
            $session = $streamContext->session();
            $checkpoint->metadata['stream_id'] = $session->streamId;
            $checkpoint->metadata['last_seq'] = $session->seq;
            $checkpoint->metadata['stream_cursor'] = $session->seq;
        }

        $this->checkpoints->save($executionId, $checkpoint);
    }

    private function runNodeWithMiddleware(
        StateContract $state,
        NodeInterface $node,
        string $executionId,
        string $nodeName,
        ?string $spanId
    ): StateContract {
        $stack = [];
        foreach ($this->globalMiddleware as $mw) {
            $stack[] = is_string($mw) ? $this->container->make($mw) : $mw;
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
                    if (app()->bound(StreamContext::class)) {
                        app(StreamContext::class)->resetNodeAttemptEmission();
                    }
                    try {
                        $node->onBefore($state);
                        $result = $node->execute($state);
                        $node->onAfter($state, $result);

                        return $result;
                    } catch (\Throwable $e) {
                        $attempts++;
                        $durationMs = (microtime(true) - $attemptStart) * 1000;
                        $willRetry = $attempts <= $max && $node->shouldRetry($e);
                        if (app()->bound(StreamContext::class)) {
                            $streamContext = app(StreamContext::class);
                            if ($streamContext->isStreamingActive() && $streamContext->nodeAttemptHasContentEmission()) {
                                $willRetry = false;
                            }
                        }
                        $startedAt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $attemptStart)) ?: new \DateTimeImmutable;
                        $traceContext = ($this->traceContextProvider)();
                        $parentSpanId = $traceContext instanceof TraceContext ? $traceContext->getParentSpanId() : null;
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
                            $parentSpanId
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
     * @param  array<string, mixed>  $payload
     */
    private function emitStreamLifecycleIfActive(
        ?StreamContext $streamContext,
        string $nodeName,
        ?string $spanId,
        ?string $parentSpanId,
        string $type,
        array $payload,
    ): void {
        if ($streamContext === null || ! $streamContext->isStreamingActive()) {
            return;
        }

        $sink = $streamContext->sink();
        if ($sink === null) {
            return;
        }

        StreamLifecycleEventEmitter::emit(
            $sink,
            $streamContext->session(),
            $nodeName,
            $spanId,
            $parentSpanId,
            $type,
            $payload,
            $streamContext->nextNodeSeq($nodeName)
        );
    }
}
