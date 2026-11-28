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
use Shahzad\AgenticGraph\Contracts\ParallelExecutorInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
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
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\InstrumentationGate;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceTime;

final class ParallelExecutionCoordinator implements ParallelExecutorInterface
{
    /** The row of agenticgraph_batch_results that holds the state at fan-out; not a node name. */
    private const BATCH_INPUT = '__input__';

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
        // Every node span parents the root graph span; fan-out is shown by the route span, not nesting.
        $parentSpanId = $traceContext instanceof TraceContext ? $traceContext->getRootSpanId() : null;
        $nodeCompletePayloadBuilder = StreamNodeCompletePayloadBuilder::fromConfig($this->stateManager);
        // Resolve before the branches are pushed: the source is the node that fanned out.
        $sourceNode = $this->resolveSourceNode($graph, $parallelNodes);
        foreach ($parallelNodes as $nodeName) {
            ExecutionRepository::stopIfDeletedQueuedRun($executionId);
            $executionContext = ($this->executionContextProvider)();
            if ($executionContext instanceof ExecutionContext) {
                $this->container->instance(ExecutionContext::class, $executionContext->pushNode($nodeName));
            }
            $node = $graph->getNode($nodeName);
            $spanId = $traceContext instanceof TraceContext ? $traceContext->startSpan($nodeName) : null;
            $inputState = $state;
            $nodeStartTime = microtime(true);
            $nodeStartDt = TraceTime::fromMicrotime($nodeStartTime);
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
            $pipeline = new NodeMiddlewarePipeline(
                $this->container,
                $this->events,
                $this->globalMiddleware,
                $this->traceContextProvider,
            );
            try {
                $resultState = $pipeline->execute($state, $node, $executionId, $nodeName, $spanId);
            } finally {
                if ($traceContext instanceof TraceContext) {
                    $traceContext->endSpan();
                }
            }
            $nodeDuration = microtime(true) - $nodeStartTime;
            // After retries, the completed span describes the successful attempt only.
            $attempt = $pipeline->lastAttempt();
            $attemptStart = $attempt['startedAt'] ?? $nodeStartTime;
            $this->events->dispatch(new NodeExecutionCompleted(
                $executionId,
                $nodeName,
                $inputState,
                $resultState,
                microtime(true) - $attemptStart,
                ['node_type' => $node::class, 'attempt' => $attempt['attempt'] ?? 1, 'attempt_of' => $spanId],
                $attempt['spanId'] ?? $spanId,
                TraceTime::fromMicrotime($attemptStart),
                $parentSpanId
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
        ExecutionRepository::stopIfDeletedQueuedRun($executionId); // Deleted during routing: no fan-out.
        $jobs = [];
        $traceContext = ($this->traceContextProvider)();
        $parentSpanId = $traceContext instanceof TraceContext ? $traceContext->getRootSpanId() : null;
        $loopMetadataArray = $this->captureLoopMetadataArray();
        foreach ($parallelNodes as $nodeName) {
            $jobs[] = new ExecuteNodeJob(
                $executionId,
                $nodeName,
                $state->toArray(),
                $graph->name,
                $parentSpanId,
                $loopMetadataArray
            );
        }

        $this->logger->debug('Dispatching parallel batch jobs', [
            'execution_id' => $executionId,
            'node_count' => count($parallelNodes),
            'nodes' => $parallelNodes,
            'parent_span_id' => $parentSpanId,
        ]);

        $sourceNode = $this->resolveSourceNode($graph, $parallelNodes);
        $this->repo->putBatchResult($executionId, self::BATCH_INPUT, $state->toArray());
        $callbacks = $this->buildBatchCallbacks(
            $graph->name,
            $executionId,
            $sourceNode,
            $parallelNodes,
            $loopMetadataArray,
            $parentSpanId
        );

        InstrumentationGate::internal(fn () => $this->bus->batch($jobs)
            ->name("agenticgraph-parallel-{$executionId}")
            ->onConnection(config('agenticgraph.execution.queue_connection', 'redis'))
            ->onQueue(config('agenticgraph.execution.queue_name', 'agenticgraph'))
            ->allowFailures(false)
            ->then($callbacks['then'])
            ->catch($callbacks['catch'])
            ->finally($callbacks['finally'])
            ->dispatch());

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
        ?string $rootSpanId = null,
    ): array {
        return [
            'then' => static function (Batch $batch) use ($graphName, $executionId, $sourceNode, $parallelNodes, $loopMetadataArray, $rootSpanId): void {
                app(ParallelExecutionCoordinator::class)->handleBatchCompletion(
                    $batch,
                    $graphName,
                    $executionId,
                    $sourceNode,
                    $parallelNodes,
                    $loopMetadataArray,
                    $rootSpanId
                );
            },
            'catch' => static function (Batch $batch, \Throwable $e) use ($graphName, $executionId, $rootSpanId): void {
                app(ParallelExecutionCoordinator::class)->handleBatchFailure($batch, $executionId, $e, $graphName, $rootSpanId);
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
        ?string $rootSpanId = null,
    ): void {
        // A deleted or ended run (skipped, failed, expired) has nothing to join; its missing results are not an error.
        if (! $this->repo->exists($executionId) || $this->repo->hasEnded($executionId)) {
            $this->logger->info('Batch completion skipped, execution row is gone or ended', ['execution_id' => $executionId]);

            return;
        }

        $graph = app(GraphRegistry::class)->get($graphName);
        if ($graph === null) {
            throw new \RuntimeException("Graph not found: {$graphName}");
        }

        $stored = $this->storedBatchResults($executionId, $targets);
        $missing = array_values(array_diff($targets, array_keys($stored)));

        if ($missing !== []) {
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

            // Laravel only reports an exception thrown from a batch callback, so the execution is ended here.
            $this->handleBatchFailure($batch, $executionId, new \RuntimeException($errorMsg), $graphName, $rootSpanId);

            return;
        }

        $results = array_map(fn (string $nodeName): State => State::fromArray($stored[$nodeName]), $targets);
        $inputData = $stored[self::BATCH_INPUT] ?? null;
        $baseState = is_array($inputData) ? State::fromArray($inputData) : new State;
        $this->stateManager->warnParallelMergeCollisions($this->logger, $baseState, $targets, $results);
        $merged = $this->stateManager->mergeAll($results);
        $this->checkpoint($graph, $executionId, $sourceNode, $merged, null);
        $next = $this->joinInSegment($graph, $executionId, $targets, $merged, $rootSpanId);

        // The snapshot was taken at fan-out; add each branch visit once.
        if ($loopMetadataArray !== null) {
            $loopContext = ExecutionContext::fromArray($loopMetadataArray, $executionId);
            foreach ($targets as $nodeName) {
                $loopContext = $loopContext->pushNode($nodeName);
            }
            $loopMetadataArray = LoopMetadata::fromExecutionContext($loopContext)->toArray();
        }

        $job = new ContinueExecutionJob(
            $graphName,
            $executionId,
            $merged->toArray(),
            $next ?? ($graph->exitPoints[0] ?? $sourceNode),
            $loopMetadataArray,
            rootSpanId: $rootSpanId
        );

        ($this->dispatchCallable)($job)
            ->onConnection(config('agenticgraph.execution.queue_connection', 'redis'))
            ->onQueue(config('agenticgraph.execution.queue_name', 'agenticgraph'));
    }

    /**
     * The batch join runs in the worker that finished last, after that job sent its segment.
     * It sends its own continued segment so the join's route span is not lost.
     *
     * @param  array<int, string>  $targets
     */
    private function joinInSegment(Graph $graph, string $executionId, array $targets, StateContract $merged, ?string $rootSpanId): ?string
    {
        $exporter = $this->container->bound(TracerExporter::class) ? $this->container->make(TracerExporter::class) : null;
        if ($rootSpanId === null || ! $exporter?->isEnabled()) {
            return $this->routingEngine->determinePostParallelNext($graph, $targets, $merged);
        }

        $previous = ($this->traceContextProvider)();
        $this->container->instance(TraceContext::class, new TraceContext($executionId, $rootSpanId));
        $exporter->resumeTrace($executionId, $graph->name, 'continued');
        try {
            return $this->routingEngine->determinePostParallelNext($graph, $targets, $merged);
        } finally {
            $exporter->flush($executionId);
            if ($previous instanceof TraceContext) {
                $this->container->instance(TraceContext::class, $previous);
            } else {
                $this->container->forgetInstance(TraceContext::class);
            }
        }
    }

    public function handleBatchFailure(
        Batch $batch,
        string $executionId,
        \Throwable $e,
        ?string $graphName = null,
        ?string $rootSpanId = null,
    ): void {
        $this->failExecution($executionId, "Batch execution failed: {$e->getMessage()}", $e, $graphName, $rootSpanId, $batch->id);
    }

    /**
     * A branch job failed for good (Laravel's JobFailed). The batch catch callback ends the execution first when the
     * batch records the failure; when its job_batches update throws (SQLite "database is locked"), no batch callback
     * runs and this ends it.
     */
    public function handleBranchJobFailure(ExecuteNodeJob $job, \Throwable $e): void
    {
        $this->failExecution($job->executionId, "Branch [{$job->nodeName}] failed: {$e->getMessage()}", $e, $job->graphName, $job->parentSpanId, $job->batchId);
    }

    /**
     * Only the call that moves the execution to failed closes the trace, dispatches GraphExecutionFailed and logs, so
     * the batch callback and the branch job failure cannot both fail it.
     */
    private function failExecution(string $executionId, string $error, \Throwable $e, ?string $graphName, ?string $rootSpanId, ?string $batchId): void
    {
        if (! $this->repo->failed($executionId, $error)) {
            return;
        }

        // Before the event: a listener cannot skip this line.
        if ((bool) config('agenticgraph.logging.enabled', true)) {
            $this->logger->error('Batch execution failed', [
                'execution_id' => $executionId,
                'batch_id' => $batchId,
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        if ($graphName === null) {
            return;
        }

        // Close the trace: the failed segment carries the root span and the error.
        $exporter = $this->container->bound(TracerExporter::class) ? $this->container->make(TracerExporter::class) : null;
        $exporter?->resumeTrace($executionId, $graphName, 'continued');
        $inputData = $this->storedBatchResults($executionId, [])[self::BATCH_INPUT] ?? null;
        $listenerError = RunEndEvent::fire(new GraphExecutionFailed(
            $executionId,
            $graphName,
            is_array($inputData) ? State::fromArray($inputData) : new State,
            $e,
            0,
            $rootSpanId
        ), $this->events);
        $exporter?->flush($executionId); // A listener that throws can keep the tracing listener from closing it.
        if ($listenerError !== null) {
            RunEndEvent::report($listenerError);
        }
    }

    /**
     * @param  array<int, string>  $targets
     */
    public function cleanupBatchResults(string $executionId, array $targets): void
    {
        $this->repo->deleteBatchResults($executionId);
        $this->cache->forget($this->batchInputCacheKey($executionId));

        foreach ($targets as $nodeName) {
            $key = $this->batchResultCacheKey($executionId, $nodeName);
            $this->cache->forget($key);
        }
    }

    /**
     * The stored results by node name, with the fan-out input under BATCH_INPUT. A job or a fan-out from before
     * 0.2.5 left its result under the cache key: that is still read, for batches in flight during the upgrade.
     *
     * @param  array<int, string>  $targets
     * @return array<string, array<string, mixed>>
     */
    private function storedBatchResults(string $executionId, array $targets): array
    {
        $stored = $this->repo->batchResults($executionId);
        foreach ($targets as $nodeName) {
            $cached = isset($stored[$nodeName]) ? null : $this->cache->get($this->batchResultCacheKey($executionId, $nodeName));
            if (is_array($cached)) {
                $stored[$nodeName] = $cached;
            }
        }
        $cachedInput = isset($stored[self::BATCH_INPUT]) ? null : $this->cache->get($this->batchInputCacheKey($executionId));
        if (is_array($cachedInput)) {
            $stored[self::BATCH_INPUT] = $cachedInput;
        }

        return $stored;
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
