<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\ParallelExecutorInterface;
use Shahzad\AgenticGraph\Contracts\RouterResolverInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\StreamingExecutorInterface;
use Shahzad\AgenticGraph\Events\CycleCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionStarted;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\ExecutionContextManager;
use Shahzad\AgenticGraph\Execution\ExecutionLoopDelegate;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\LoopGuard;
use Shahzad\AgenticGraph\Execution\LoopLimitExceededException;
use Shahzad\AgenticGraph\Execution\LoopMetadata;
use Shahzad\AgenticGraph\Execution\NodeMiddlewarePipeline;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\State\StateManager;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceTime;

class StreamingExecutionCoordinator implements StreamingExecutorInterface
{
    private ExecutionLoopDelegate $delegate;

    private LoopGuard $loopGuard;

    private ?StreamContext $streamContext = null;

    private ExecutionContextManager $contextManager;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly CacheManager $cache,
        private readonly LoggerInterface $logger,
        private readonly ExecutionRepository $repo,
        private readonly ParallelExecutorInterface $parallelExecutor,
        private readonly RouterResolverInterface $routerResolver,
        private readonly StreamSinkFactory $sinkFactory,
        private readonly Container $container,
        private readonly ?TracerExporter $tracerExporter = null,
        ?ExecutionContextManager $contextManager = null
    ) {
        $loopConfig = config('agenticgraph.execution.loop', []);
        $this->loopGuard = new LoopGuard(
            maxGlobalIterations: $loopConfig['max_iterations'] ?? 1000,
            maxCycleIterations: $loopConfig['max_cycle_iterations'] ?? null,
            timeoutSeconds: $loopConfig['timeout_seconds'] ?? null,
            enabled: $loopConfig['enabled'] ?? false,
        );
        $this->contextManager = $contextManager ?? $container->make(ExecutionContextManager::class);
    }

    public function setDelegate(ExecutionLoopDelegate $delegate): void
    {
        $this->delegate = $delegate;
    }

    public function streamContext(): ?StreamContext
    {
        return $this->streamContext;
    }

    public function execute(Graph $graph, StateContract $state, StreamOptions $options, string $executionId, ?ExecutionOptions $executionOptions = null): StreamHandle
    {
        $state->validate();
        if ($this->container->bound(TraceContext::class)) {
            $traceContext = $this->container->make(TraceContext::class);
            $scope = $this->contextManager->enterWithTrace(
                $executionId,
                $graph->name,
                new ExecutionOptions,
                $traceContext,
                null,
                $graph
            );
        } else {
            $scope = $this->contextManager->enter($executionId, $graph->name, new ExecutionOptions, null, $graph);
            $traceContext = $scope->traceContext;
        }
        $executionContext = $scope->context;

        $session = null;
        $baseSink = null;
        $sink = null;
        $executionStartTime = microtime(true);

        try {
            if ($this->container->bound(StreamContext::class)) {
                $session = $this->container->make(StreamContext::class)->session();
            }
            if (! $session) {
                $session = new StreamSession(
                    streamId: uniqid('stream_', true),
                    executionId: $executionId,
                    options: $options,
                    startedAtUnix: time()
                );
            }

            $baseSink = $this->createStreamSink($session, $options);
            $sink = $this->wrapStreamSink($baseSink, $options, $session);
            if ($this->container->bound(StreamContext::class)) {
                $context = $this->container->make(StreamContext::class);
                $context->setSink($sink);
                $this->streamContext = $context;
            } else {
                $context = new StreamContext($session, $sink);
                $this->container->instance(StreamContext::class, $context);
                $this->streamContext = $context;
            }

            if (! $this->repo->get($executionId)) {
                $this->repo->queued($executionId, $graph->name, State::toStoragePayload($state), $session->streamId, $executionOptions);
            }
            $this->repo->started($executionId, $graph->name, State::toStoragePayload($state), $session->streamId, $executionOptions);

            $rootSpanId = $traceContext->getRootSpanId();
            $executionStartDt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $executionStartTime)) ?: new \DateTimeImmutable;
            $this->events->dispatch(new GraphExecutionStarted($executionId, $graph->name, $state, $executionStartDt, $rootSpanId));

            $this->emitLifecycleEvent(
                $sink,
                $session,
                null,
                $traceContext->getSpanId(),
                $traceContext->getParentSpanId(),
                StreamLifecycleEventTypes::GRAPH_START,
                ['graph_name' => $graph->name]
            );
        } catch (\Throwable $e) {
            $this->handleStreamingSetupFailure(
                $graph,
                $state,
                $executionId,
                $e,
                $sink ?? $baseSink,
                $session,
                $traceContext,
                $executionStartTime
            );
            $this->contextManager->exitWithException($scope, $e);
            $this->cleanupStreamContext();
            throw $e;
        }

        try {
            $handle = $this->performStreamingExecution(
                $graph,
                $state,
                $graph->entryPoint,
                $executionId,
                $options,
                $traceContext,
                $executionContext,
                $session,
                $baseSink,
                $sink,
                $executionStartTime
            );

            $finalState = $handle->getFinalState();
            $exitState = $finalState->hasInterrupt() ? null : $finalState;
            $this->contextManager->exit($scope, $exitState);

            return $handle;
        } catch (\Throwable $e) {
            $this->contextManager->exitWithException($scope, $e);
            throw $e;
        } finally {
            $this->cleanupStreamContext();
        }
    }

    public function executeFromNode(
        Graph $graph,
        StateContract $state,
        string $startNode,
        StreamOptions $options,
        string $executionId,
        ?LoopMetadata $loopMetadata = null
    ): StreamHandle {
        return $this->executeFromNodeWithResume($graph, $state, $startNode, $options, $executionId, $loopMetadata);
    }

    public function executeFromNodeWithResume(
        Graph $graph,
        StateContract $state,
        string $startNode,
        StreamOptions $options,
        string $executionId,
        ?LoopMetadata $loopMetadata = null,
        ?StreamResumePayload $resumePayload = null
    ): StreamHandle {
        $state->validate();

        if ($this->container->bound(TraceContext::class)) {
            $traceContext = $this->container->make(TraceContext::class);
            $scope = $this->contextManager->enterWithTrace(
                $executionId,
                $graph->name,
                new ExecutionOptions,
                $traceContext,
                $loopMetadata,
                $graph
            );
        } else {
            $scope = $this->contextManager->enter($executionId, $graph->name, new ExecutionOptions, $loopMetadata, $graph);
            $traceContext = $scope->traceContext;
        }
        $executionContext = $scope->context;

        $session = null;
        $baseSink = null;
        $sink = null;
        $setupStartTime = microtime(true);

        try {
            if ($this->container->bound(StreamContext::class)) {
                $session = $this->container->make(StreamContext::class)->session();
            }
            if (! $session) {
                $session = new StreamSession(
                    streamId: uniqid('stream_', true),
                    executionId: $executionId,
                    options: $options,
                    startedAtUnix: time()
                );
            }

            $baseSink = $this->createStreamSink($session, $options);
            $sink = $this->wrapStreamSink($baseSink, $options, $session);
            if ($this->container->bound(StreamContext::class)) {
                $context = $this->container->make(StreamContext::class);
                $context->setSink($sink);
                $this->streamContext = $context;
            } else {
                $context = new StreamContext($session, $sink);
                $this->container->instance(StreamContext::class, $context);
                $this->streamContext = $context;
            }

            $currentStatus = $this->repo->getStatus($executionId);
            if ($currentStatus === 'queued' || $currentStatus === 'interrupted') {
                $this->repo->started($executionId, $graph->name, State::toStoragePayload($state), $session->streamId);
            }

            if ($this->tracerExporter && $this->tracerExporter->isEnabled() && $this->tracerExporter->getTraceStartTime($executionId) === null) {
                $executionStartDt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true))) ?: new \DateTimeImmutable;
                $this->events->dispatch(new GraphExecutionStarted($executionId, $graph->name, $state, $executionStartDt, $traceContext->getRootSpanId()));
            }

            if ($resumePayload !== null) {
                $this->emitLifecycleEvent(
                    $sink,
                    $session,
                    $startNode,
                    $traceContext->getSpanId(),
                    $traceContext->getParentSpanId(),
                    StreamLifecycleEventTypes::STREAM_RESUME,
                    [
                        'from_seq' => $resumePayload->fromSeq,
                        'resumed_at_seq' => $resumePayload->resumedAtSeq,
                        'gap_events' => $resumePayload->gapEvents,
                        'checkpoint_id' => $resumePayload->checkpointId,
                    ]
                );
            }
        } catch (\Throwable $e) {
            $this->handleStreamingSetupFailure(
                $graph,
                $state,
                $executionId,
                $e,
                $sink ?? $baseSink,
                $session,
                $traceContext,
                $setupStartTime
            );
            $this->contextManager->exitWithException($scope, $e);
            $this->cleanupStreamContext();
            throw $e;
        }

        $start = microtime(true);

        try {
            $handle = $this->performStreamingExecution(
                $graph,
                $state,
                $startNode,
                $executionId,
                $options,
                $traceContext,
                $executionContext,
                $session,
                $baseSink,
                $sink,
                $start,
                skipInterruptGateForEntry: $resumePayload !== null,
                entryAlreadyCounted: $resumePayload !== null && $loopMetadata !== null
            );

            $finalState = $handle->getFinalState();
            $exitState = $finalState->hasInterrupt() ? null : $finalState;
            $this->contextManager->exit($scope, $exitState);

            return $handle;
        } catch (\Throwable $e) {
            $this->contextManager->exitWithException($scope, $e);
            throw $e;
        } finally {
            $this->cleanupStreamContext();
        }
    }

    private function performStreamingExecution(
        Graph $graph,
        StateContract $state,
        string $current,
        string $executionId,
        StreamOptions $options,
        TraceContext $traceContext,
        ExecutionContext $executionContext,
        StreamSession $session,
        StreamSinkInterface $baseSink,
        StreamSinkInterface $sink,
        float $start,
        bool $skipInterruptGateForEntry = false,
        bool $entryAlreadyCounted = false
    ): StreamHandle {
        try {
            $interrupted = false;
            $skipEntryInterruptGate = $skipInterruptGateForEntry;
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

                    // An interrupted node was counted before it paused; its restored loop context keeps that visit.
                    if ($entryAlreadyCounted) {
                        $entryAlreadyCounted = false;
                    } else {
                        [$executionContext, $cycle] = $executionContext->detectAndRecordCycle($current);
                        $executionContext = $executionContext->pushNode($current);

                        try {
                            $this->loopGuard->check($executionContext, $cycle);
                            $this->loopGuard->checkCyclicSafetyNet($executionContext, $graph->hasCycles());
                        } catch (LoopLimitExceededException $e) {
                            $this->repo->failed($executionId, $e->getMessage());
                            throw $e;
                        }

                        $executionContext = $executionContext->incrementIteration();
                        $this->container->instance(ExecutionContext::class, $executionContext);

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
                    }

                    $node = $graph->getNode($current);
                    if (! $skipEntryInterruptGate && $options->interrupts && $this->delegate->shouldInterrupt($node, $state)) {
                        $state = $this->delegate->handleInterrupt($executionId, $graph->name, $current, $state, $this->streamContext);
                        $lock->release();
                        $interrupted = true;
                        break;
                    }
                    $skipEntryInterruptGate = false;

                    $spanId = $traceContext->startSpan($current);
                    $parentSpanId = $traceContext->getParentSpanId();
                    $nodeStartTime = microtime(true);
                    $nodeStartDt = TraceTime::fromMicrotime($nodeStartTime);
                    $inputState = $state;

                    $this->events->dispatch(new NodeExecutionStarted(
                        $executionId,
                        $current,
                        $state,
                        $nodeStartDt,
                        $spanId,
                        $parentSpanId
                    ));

                    $this->streamContext?->resetNodeSeq($current);

                    $this->emitLifecycleEvent(
                        $sink,
                        $session,
                        $current,
                        $spanId,
                        $parentSpanId,
                        StreamLifecycleEventTypes::NODE_START,
                        ['node_name' => $current],
                        $this->streamContext?->nextNodeSeq($current)
                    );

                    $pipeline = new NodeMiddlewarePipeline(
                        $this->container,
                        $this->events,
                        null,
                        fn (): ?TraceContext => $this->container->bound(TraceContext::class) ? $this->container->make(TraceContext::class) : null,
                    );
                    try {
                        $state = $pipeline->execute($state, $node, $executionId, $current, $spanId);
                    } catch (\Throwable $e) {
                        // The pipeline already dispatched NodeExecutionFailed for each attempt.
                        $this->emitLifecycleEvent(
                            $sink,
                            $session,
                            $current,
                            $spanId,
                            $parentSpanId,
                            StreamLifecycleEventTypes::NODE_ERROR,
                            [
                                'node_name' => $current,
                                'error_type' => $e::class,
                                'message' => $e->getMessage(),
                            ],
                            $this->streamContext?->nextNodeSeq($current)
                        );

                        $traceContext->endSpan();

                        // The outer catch emits graph_failed, then closes the stream once.
                        throw $e;
                    }

                    $nodeDuration = microtime(true) - $nodeStartTime;
                    // After retries, the completed span describes the successful attempt only.
                    $attempt = $pipeline->lastAttempt();
                    $attemptStart = $attempt['startedAt'] ?? $nodeStartTime;
                    $this->events->dispatch(new NodeExecutionCompleted(
                        $executionId,
                        $current,
                        $inputState,
                        $state,
                        microtime(true) - $attemptStart,
                        ['node_type' => $node::class, 'attempt' => $attempt['attempt'] ?? 1, 'attempt_of' => $spanId],
                        $attempt['spanId'] ?? $spanId,
                        TraceTime::fromMicrotime($attemptStart),
                        $parentSpanId
                    ));

                    $this->delegate->checkpoint($graph, $executionId, $current, $state, $executionContext);

                    $nodeCompletePayload = StreamNodeCompletePayloadBuilder::fromConfig(
                        $this->container->make(StateManager::class)
                    )->build($current, $nodeDuration * 1000, $inputState, $state);

                    $this->emitLifecycleEvent(
                        $sink,
                        $session,
                        $current,
                        $spanId,
                        $parentSpanId,
                        StreamLifecycleEventTypes::NODE_COMPLETE,
                        $nodeCompletePayload,
                        $this->streamContext?->nextNodeSeq($current)
                    );

                    if (in_array($current, $graph->exitPoints, true)) {
                        $traceContext->endSpan();
                        break;
                    }
                    $next = $this->determineNext($graph, $current, $state);
                    if ($next === null) {
                        $traceContext->endSpan();
                        break;
                    }
                    if (is_array($next)) {
                        $result = $this->parallelExecutor->executeParallel(
                            $graph,
                            $next,
                            $state,
                            $executionId,
                            $this->streamContext
                        );
                        // The branches recorded their visits in the bound loop context.
                        $executionContext = $this->container->make(ExecutionContext::class);
                        $state = $result->state;
                        $current = $result->nextNode;
                    } else {
                        $current = $next;
                    }
                    $traceContext->endSpan();
                } catch (LockTimeoutException $e) {
                    $this->repo->failed($executionId, 'Lock timeout: '.$e->getMessage());
                    throw new \RuntimeException('Execution locked by another process', 0, $e);
                } finally {
                    optional($lock)->release();
                }
            }

            $duration = microtime(true) - $start;
            if (! $interrupted) {
                $this->repo->completed($executionId, State::toStoragePayload($state), $duration);
                $completedAt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true))) ?: new \DateTimeImmutable;
                $this->events->dispatch(new GraphExecutionCompleted($executionId, $graph->name, $state, $completedAt, $duration, [], $traceContext->getRootSpanId()));

                $this->emitLifecycleEvent(
                    $sink,
                    $session,
                    null,
                    $traceContext->getSpanId(),
                    $traceContext->getParentSpanId(),
                    StreamLifecycleEventTypes::GRAPH_COMPLETE,
                    ['duration_ms' => $duration * 1000]
                );

                $sink->close(StreamStatus::SUCCESS, [
                    'final_state' => State::toStoragePayload($state),
                ]);
            }

            return new StreamHandle($session, $baseSink, $state);
        } catch (\Throwable $e) {
            $duration = microtime(true) - $start;
            $this->events->dispatch(new GraphExecutionFailed($executionId, $graph->name, $state, $e, $duration * 1000, $traceContext->getRootSpanId()));

            $this->endFailedStream($sink, $session, $traceContext, $e, $state);

            $this->repo->failed($executionId, $e->getMessage());

            throw $e;
        }
    }

    /**
     * graph_failed, then one close (execution_terminal + broadcast stream_close), always in that order.
     * Delivery errors here are dropped: the original failure is what the caller rethrows, and a
     * broken transport must not skip the close. The store is written before the transport.
     */
    private function endFailedStream(StreamSinkInterface $sink, StreamSession $session, TraceContext $traceContext, \Throwable $e, StateContract $state): void
    {
        try {
            $this->emitLifecycleEvent(
                $sink,
                $session,
                null,
                $traceContext->getSpanId(),
                $traceContext->getParentSpanId(),
                StreamLifecycleEventTypes::GRAPH_FAILED,
                [
                    'error_type' => $e::class,
                    'message' => $e->getMessage(),
                ]
            );
        } catch (\Throwable) {
        }

        try {
            $sink->close(StreamStatus::ERROR, [
                'error_type' => $e::class,
                'message' => $e->getMessage(),
                'final_state' => State::toStoragePayload($state),
            ]);
        } catch (\Throwable) {
        }
    }

    private function createStreamSink(StreamSession $session, StreamOptions $options): StreamSinkInterface
    {
        return $this->sinkFactory->create($session, $options);
    }

    private function wrapStreamSink(StreamSinkInterface $sink, StreamOptions $options, StreamSession $session): StreamSinkInterface
    {
        return $this->sinkFactory->wrap($sink, $options, $session);
    }

    public function emitLifecycleEvent(
        StreamSinkInterface $sink,
        StreamSession $session,
        ?string $nodeName,
        ?string $spanId,
        ?string $parentSpanId,
        string $type,
        mixed $payload,
        ?int $nodeSeq = null
    ): void {
        StreamLifecycleEventEmitter::emit(
            $sink,
            $session,
            $nodeName,
            $spanId,
            $parentSpanId,
            $type,
            $payload,
            $nodeSeq
        );
    }

    protected function determineNext(Graph $graph, string $current, StateContract $state): mixed
    {
        return $this->routerResolver->determineNext($graph, $current, $state);
    }

    private function handleStreamingSetupFailure(
        Graph $graph,
        StateContract $state,
        string $executionId,
        \Throwable $e,
        ?StreamSinkInterface $sink,
        ?StreamSession $session,
        TraceContext $traceContext,
        float $start
    ): void {
        $duration = microtime(true) - $start;
        $this->events->dispatch(new GraphExecutionFailed($executionId, $graph->name, $state, $e, $duration * 1000, $traceContext->getRootSpanId()));

        if ($sink !== null && $session !== null) {
            $this->endFailedStream($sink, $session, $traceContext, $e, $state);
        }

        $this->repo->failed($executionId, $e->getMessage());
    }

    private function cleanupStreamContext(): void
    {
        $this->container->forgetInstance(StreamContext::class);
    }
}
