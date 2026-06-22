<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\ParallelExecutorInterface;
use Shahzad\AgenticGraph\Contracts\RouterResolverInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\StreamingExecutorInterface;
use Shahzad\AgenticGraph\Events\CycleCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionFailed;
use Shahzad\AgenticGraph\Events\NodeExecutionStarted;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\ExecutionContextManager;
use Shahzad\AgenticGraph\Execution\ExecutionLoopDelegate;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\LoopGuard;
use Shahzad\AgenticGraph\Execution\LoopLimitExceededException;
use Shahzad\AgenticGraph\Execution\LoopMetadata;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Middleware\LoggingMiddleware;
use Shahzad\AgenticGraph\Middleware\TimingMiddleware;
use Shahzad\AgenticGraph\Middleware\ValidationMiddleware;
use Shahzad\AgenticGraph\Nodes\Contracts\StreamableNodeInterface;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;

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
        private readonly ?PulseExporter $pulseExporter = null,
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
                $traceContext
            );
        } else {
            $scope = $this->contextManager->enter($executionId, $graph->name, new ExecutionOptions);
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
                'graph_start',
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
                $loopMetadata
            );
        } else {
            $scope = $this->contextManager->enter($executionId, $graph->name, new ExecutionOptions, $loopMetadata);
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

            if ($this->pulseExporter && $this->pulseExporter->isEnabled() && $this->pulseExporter->getTraceStartTime($executionId) === null) {
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
                    'stream_resume',
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
                skipInterruptGateForEntry: $resumePayload !== null
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
        bool $skipInterruptGateForEntry = false
    ): StreamHandle {
        try {
            $interrupted = false;
            $nodeSeqs = new \stdClass;
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

                    $executionContext = $executionContext->pushNode($current);
                    [$executionContext, $cycle] = $executionContext->detectAndRecordCycle($current);

                    try {
                        $this->loopGuard->check($executionContext, $cycle);
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
                    $nodeStartDt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $nodeStartTime)) ?: new \DateTimeImmutable;
                    $inputState = $state;

                    $this->events->dispatch(new NodeExecutionStarted(
                        $executionId,
                        $current,
                        $state,
                        $nodeStartDt,
                        $spanId,
                        $parentSpanId
                    ));

                    $this->emitLifecycleEvent(
                        $sink,
                        $session,
                        $current,
                        $spanId,
                        $parentSpanId,
                        'node_start',
                        ['node_name' => $current],
                        $this->nextNodeSeq($nodeSeqs, $current)
                    );

                    try {
                        if ($node instanceof StreamableNodeInterface && $this->shouldExecuteStreaming($node)) {
                            $node->onBefore($state);
                            $state = $node->executeStreaming($state, $sink);
                            $node->onAfter($inputState, $state);
                        } else {
                            $state = $this->runNodeWithMiddleware($state, $node, $executionId, $current, $spanId);
                        }
                    } catch (\Throwable $e) {
                        $nodeDuration = microtime(true) - $nodeStartTime;
                        $this->events->dispatch(new NodeExecutionFailed(
                            $executionId,
                            $spanId,
                            $current,
                            $state,
                            $e,
                            $nodeDuration * 1000,
                            1,
                            false,
                            $nodeStartDt,
                            $parentSpanId
                        ));

                        $this->emitLifecycleEvent(
                            $sink,
                            $session,
                            $current,
                            $spanId,
                            $parentSpanId,
                            'node_error',
                            [
                                'node_name' => $current,
                                'error_type' => $e::class,
                                'message' => $e->getMessage(),
                            ],
                            $this->nextNodeSeq($nodeSeqs, $current)
                        );

                        $sink->close(StreamStatus::ERROR, [
                            'error_type' => $e::class,
                            'message' => $e->getMessage(),
                            'final_state' => State::toStoragePayload($state),
                        ]);

                        $traceContext->endSpan();

                        throw $e;
                    }

                    $nodeDuration = microtime(true) - $nodeStartTime;
                    $this->events->dispatch(new NodeExecutionCompleted(
                        $executionId,
                        $current,
                        $inputState,
                        $state,
                        $nodeDuration,
                        ['node_type' => $node::class],
                        $spanId,
                        $nodeStartDt,
                        $parentSpanId
                    ));

                    $this->delegate->checkpoint($graph, $executionId, $current, $state, $executionContext);

                    $this->emitLifecycleEvent(
                        $sink,
                        $session,
                        $current,
                        $spanId,
                        $parentSpanId,
                        'node_complete',
                        [
                            'node_name' => $current,
                            'duration_ms' => $nodeDuration * 1000,
                        ],
                        $this->nextNodeSeq($nodeSeqs, $current)
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
                        $state = $result->state;
                        $current = $result->nextNode;
                    } else {
                        $current = $next;
                    }
                    $traceContext->endSpan();
                } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
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
                    'graph_complete',
                    ['duration_ms' => $duration * 1000]
                );

                $sink->close(StreamStatus::SUCCESS, [
                    'final_state' => State::toStoragePayload($state),
                ]);
            }

            return new StreamHandle($session, $baseSink, $state);
        } catch (\Throwable $e) {
            $duration = microtime(true) - $start;
            $this->events->dispatch(new GraphExecutionFailed($executionId, $graph->name, $state, $e, $duration * 1000));

            $this->emitLifecycleEvent(
                $sink,
                $session,
                null,
                $traceContext->getSpanId(),
                $traceContext->getParentSpanId(),
                'graph_failed',
                [
                    'error_type' => $e::class,
                    'message' => $e->getMessage(),
                ]
            );

            $sink->close(StreamStatus::ERROR, [
                'error_type' => $e::class,
                'message' => $e->getMessage(),
                'final_state' => State::toStoragePayload($state),
            ]);

            $this->repo->failed($executionId, $e->getMessage());

            throw $e;
        }
    }

    protected function runNodeWithMiddleware(StateContract $state, NodeInterface $node, string $executionId, string $nodeName, ?string $spanId): StateContract
    {
        $stack = [];
        foreach (config('agenticgraph.middleware.global', [
            LoggingMiddleware::class,
            TimingMiddleware::class,
            ValidationMiddleware::class,
        ]) as $mw) {
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
                            $this->container->make(TraceContext::class)->getParentSpanId()
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

    private function nextNodeSeq(\stdClass $nodeSeqs, string $nodeName): int
    {
        $current = property_exists($nodeSeqs, $nodeName) ? (int) $nodeSeqs->{$nodeName} : 0;
        $next = $current + 1;
        $nodeSeqs->{$nodeName} = $next;

        return $next;
    }

    private function shouldExecuteStreaming(StreamableNodeInterface $node): bool
    {
        if ($node instanceof \Shahzad\AgenticGraph\Nodes\PrismNode) {
            $method = new \ReflectionMethod($node, 'executeStreaming');

            return $method->getDeclaringClass()->getName() !== \Shahzad\AgenticGraph\Nodes\PrismNode::class;
        }

        return true;
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
        $this->events->dispatch(new GraphExecutionFailed($executionId, $graph->name, $state, $e, $duration * 1000));

        if ($sink !== null && $session !== null) {
            $this->emitLifecycleEvent(
                $sink,
                $session,
                null,
                $traceContext->getSpanId(),
                $traceContext->getParentSpanId(),
                'graph_failed',
                [
                    'error_type' => $e::class,
                    'message' => $e->getMessage(),
                ]
            );

            $sink->close(StreamStatus::ERROR, [
                'error_type' => $e::class,
                'message' => $e->getMessage(),
                'final_state' => State::toStoragePayload($state),
            ]);
        }

        $this->repo->failed($executionId, $e->getMessage());
    }

    private function cleanupStreamContext(): void
    {
        $this->container->forgetInstance(StreamContext::class);
    }
}
