<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\ParallelExecutorInterface;
use Shahzad\AgenticGraph\Contracts\RouterResolverInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\CycleCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionFailed;
use Shahzad\AgenticGraph\Events\NodeExecutionStarted;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Middleware\LoggingMiddleware;
use Shahzad\AgenticGraph\Middleware\TimingMiddleware;
use Shahzad\AgenticGraph\Middleware\ValidationMiddleware;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\AsyncStreamCoordinator;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamHandle;
use Shahzad\AgenticGraph\Streaming\StreamingExecutionCoordinator;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumePayload;
use Shahzad\AgenticGraph\Tracing\Instrumentation\HttpClientInstrumentation;
use Shahzad\AgenticGraph\Tracing\TraceContext;

class ExecutionCoordinator
{
    /**
     * @var array<int, class-string|object>
     */
    private array $globalMiddleware;

    private LoopGuard $loopGuard;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly CacheManager $cache,
        private readonly LoggerInterface $logger,
        private readonly ExecutionRepository $repo,
        private readonly RouterResolverInterface $routerResolver,
        private readonly ParallelExecutorInterface $parallelExecutor,
        private readonly StreamingExecutionCoordinator $streamingCoordinator,
        private readonly AsyncStreamCoordinator $asyncStreamCoordinator,
        private readonly ?HttpClientInstrumentation $httpClientInstrumentation,
        private readonly InterruptServiceInterface $interrupts,
        private readonly ExecutionContextManager $contextManager,
        private readonly GraphRegistry $graphRegistry,
        private readonly ExecutionStore $store,
        private readonly ExecutionStatusResolver $statusResolver,
        private readonly CheckpointManager $checkpoints,
        private readonly Container $container
    ) {
        $this->globalMiddleware = config('agenticgraph.middleware.global', [
            LoggingMiddleware::class,
            TimingMiddleware::class,
            ValidationMiddleware::class,
        ]);
        $loopConfig = config('agenticgraph.execution.loop', []);
        $this->loopGuard = new LoopGuard(
            maxGlobalIterations: $loopConfig['max_iterations'] ?? 1000,
            maxCycleIterations: $loopConfig['max_cycle_iterations'] ?? null,
            timeoutSeconds: $loopConfig['timeout_seconds'] ?? null,
            enabled: $loopConfig['enabled'] ?? false,
        );
    }

    public static function fromContainer(
        ?Dispatcher $events = null,
        ?CacheManager $cache = null,
        ?LoggerInterface $logger = null,
        ?ExecutionRepository $repo = null,
        ?RouterResolverInterface $routerResolver = null,
        ?ParallelExecutorInterface $parallelExecutor = null,
        ?StreamingExecutionCoordinator $streamingCoordinator = null,
        ?AsyncStreamCoordinator $asyncStreamCoordinator = null,
        ?HttpClientInstrumentation $httpClientInstrumentation = null,
        ?InterruptServiceInterface $interrupts = null,
        ?ExecutionContextManager $contextManager = null
    ): self {
        $container = app();
        $router = $routerResolver
            ?? ($container->bound(RouterResolverInterface::class)
                ? $container->make(RouterResolverInterface::class)
                : new RoutingEngine($container));

        return new self(
            events: $events ?? $container->make(Dispatcher::class),
            cache: $cache ?? $container->make(CacheManager::class),
            logger: $logger ?? $container->make(LoggerInterface::class),
            repo: $repo ?? $container->make(ExecutionRepository::class),
            routerResolver: $router,
            parallelExecutor: $parallelExecutor ?? $container->make(ParallelExecutorInterface::class),
            streamingCoordinator: $streamingCoordinator ?? $container->make(StreamingExecutionCoordinator::class),
            asyncStreamCoordinator: $asyncStreamCoordinator ?? $container->make(AsyncStreamCoordinator::class),
            httpClientInstrumentation: $httpClientInstrumentation
                ?? ($container->bound(HttpClientInstrumentation::class) ? $container->make(HttpClientInstrumentation::class) : null),
            interrupts: $interrupts ?? $container->make(InterruptServiceInterface::class),
            contextManager: $contextManager ?? $container->make(ExecutionContextManager::class),
            graphRegistry: $container->make(GraphRegistry::class),
            store: $container->make(ExecutionStore::class),
            statusResolver: $container->make(ExecutionStatusResolver::class),
            checkpoints: $container->make(CheckpointManager::class),
            container: $container
        );
    }

    public function setDelegate(ExecutionLoopDelegate $delegate): void
    {
        $this->streamingCoordinator->setDelegate($delegate);
    }

    public function execute(Graph $graph, StateContract $state, bool $useBatch = false, ?string $executionId = null): StateContract
    {
        $state->validate();

        $executionId = $executionId ?? $this->generateExecutionId();
        $scope = $this->enterScope($executionId, $graph, new ExecutionOptions);
        $traceContext = $scope->traceContext;
        $executionContext = $scope->context;

        if (! $this->repo->get($executionId)) {
            $this->repo->queued($executionId, $graph->name, State::toStoragePayload($state));
        }
        $this->repo->started($executionId, $graph->name, State::toStoragePayload($state));
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
                    if ($this->shouldInterrupt($node, $state)) {
                        $state = $this->handleInterrupt($executionId, $graph->name, $current, $state);
                        $lock->release();
                        break;
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
                            $this->parallelExecutor->executeParallelViaBatch(
                                $graph,
                                $next,
                                $state,
                                $executionId
                            );

                            $traceContext->endSpan();
                            $this->contextManager->exit($scope);

                            return $state;
                        }
                        $result = $this->parallelExecutor->executeParallel(
                            $graph,
                            $next,
                            $state,
                            $executionId
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
            $this->repo->completed($executionId, State::toStoragePayload($state), $duration);
            $completedAt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true))) ?: new \DateTimeImmutable;
            $this->events->dispatch(new GraphExecutionCompleted($executionId, $graph->name, $state, $completedAt, $duration, [], $traceContext->getRootSpanId()));

            $exitState = $state->hasInterrupt() ? null : $state;
            $this->contextManager->exit($scope, $exitState);

            return $state;
        } catch (\Throwable $e) {
            $duration = microtime(true) - $start;
            $this->events->dispatch(new GraphExecutionFailed($executionId, $graph->name, $state, $e, $duration * 1000));
            $this->repo->failed($executionId, $e->getMessage());
            $this->contextManager->exitWithException($scope, $e);
            throw $e;
        }
    }

    public function executeWithOptions(Graph $graph, StateContract $state, ExecutionOptions $options, ?string $executionId = null): ExecutionHandle
    {
        $options = $options->withStreaming($this->resolveStreamingMode($options));

        if ($options->mode === ExecutionMode::SYNC) {
            $executionId = $executionId ?? $this->generateExecutionId();
            if ($options->streaming === StreamingMode::NONE) {
                $finalState = $this->execute($graph, $state, executionId: $executionId);

                return new ExecutionHandle(
                    executionId: $executionId,
                    options: $options,
                    executor: $this->container->make(GraphExecutor::class),
                    store: $this->store,
                    resolver: $this->statusResolver,
                    checkpoints: $this->checkpoints,
                    status: ExecutionStatus::COMPLETED,
                    finalState: $finalState
                );
            }

            $streamOptions = new StreamOptions(
                delivery: $options->streaming->value,
                interrupts: $options->interrupts
            );
            $streamHandle = $this->executeWithStreaming($graph->name, $state, $streamOptions, $executionId);

            return new ExecutionHandle(
                executionId: $streamHandle->executionId(),
                options: $options,
                executor: $this->container->make(GraphExecutor::class),
                store: $this->store,
                resolver: $this->statusResolver,
                checkpoints: $this->checkpoints,
                streamHandle: $streamHandle,
                status: ExecutionStatus::COMPLETED,
                finalState: $streamHandle->getFinalState(),
                streamId: $streamHandle->streamId()
            );
        }

        if ($options->streaming === StreamingMode::NONE) {
            $asyncExecutionId = $this->executeAsync($graph, $state);
            $record = $this->store->get($asyncExecutionId);

            return new ExecutionHandle(
                executionId: $asyncExecutionId,
                options: $options,
                executor: $this->container->make(GraphExecutor::class),
                store: $this->store,
                resolver: $this->statusResolver,
                checkpoints: $this->checkpoints,
                status: $record === null ? ExecutionStatus::QUEUED : $record->status
            );
        }

        $result = $this->asyncStreamCoordinator->streamAsyncWorkflow($graph->name, $state, $options);
        $record = $this->store->get($result['executionId']);

        return new ExecutionHandle(
            executionId: $result['executionId'],
            options: $options,
            executor: $this->container->make(GraphExecutor::class),
            store: $this->store,
            resolver: $this->statusResolver,
            checkpoints: $this->checkpoints,
            status: $record === null ? ExecutionStatus::QUEUED : $record->status,
            streamId: $result['streamId']
        );
    }

    public function executeWithStreaming(string $name, StateContract $state, StreamOptions $options, ?string $executionId = null): StreamHandle
    {
        $graph = $this->graphRegistry->getOrBuild($name);
        if ($graph === null) {
            throw new \RuntimeException(
                "Graph '{$name}' not found in registry or config. ".
                "Please register the graph manually or add it to config/agenticgraph.php 'graphs' array."
            );
        }

        $executionId = $executionId ?? $this->generateExecutionId();

        return $this->streamingCoordinator->execute($graph, $state, $options, $executionId);
    }

    public function executeWithStreamingFromNode(
        Graph $graph,
        StateContract $state,
        string $startNode,
        string $executionId,
        StreamOptions $options,
        ?LoopMetadata $loopMetadata = null,
        ?StreamResumePayload $resumePayload = null
    ): StreamHandle {
        return $this->streamingCoordinator->executeFromNodeWithResume(
            $graph,
            $state,
            $startNode,
            $options,
            $executionId,
            $loopMetadata,
            $resumePayload
        );
    }

    public function resolveStreamingMode(ExecutionOptions $options): StreamingMode
    {
        if ($options->streaming !== StreamingMode::AUTO) {
            return $options->streaming;
        }

        $sseEnabled = (bool) config('agenticgraph.streaming.sse.enabled', true);
        $forceAboveSeconds = (int) config('agenticgraph.streaming.broadcast.force_above_seconds', 0);
        $estimated = $options->estimatedDurationSeconds;

        if (! $sseEnabled) {
            return StreamingMode::BROADCAST;
        }

        if ($estimated !== null && $forceAboveSeconds > 0 && $estimated >= $forceAboveSeconds) {
            return StreamingMode::BROADCAST;
        }

        return StreamingMode::SSE;
    }

    public function executeAsync(Graph $graph, StateContract $state): string
    {
        $state->validate();

        $executionId = $this->generateExecutionId();
        $scope = $this->contextManager->enter($executionId, $graph->name, new ExecutionOptions(ExecutionMode::ASYNC, StreamingMode::NONE));
        $traceContext = $scope->traceContext;
        $rootSpanId = $traceContext->getRootSpanId();

        $this->repo->queued($executionId, $graph->name, State::toStoragePayload($state));

        $this->contextManager->exit($scope);
        if ($this->httpClientInstrumentation) {
            $this->httpClientInstrumentation->cleanupExecution($executionId);
        }

        dispatch(new \Shahzad\AgenticGraph\Jobs\ExecuteGraphJob(
            $graph->name,
            $executionId,
            $state->toArray(),
            checkpointId: null,
            rootSpanId: $rootSpanId
        ));

        return $executionId;
    }

    public function resume(string $checkpointId): StateContract
    {
        return $this->interrupts->resume($checkpointId);
    }

    public function resumeWithInput(string $checkpointId, mixed $humanInput): StateContract
    {
        return $this->interrupts->resumeWithInput($checkpointId, $humanInput);
    }

    public function shouldInterrupt(NodeInterface $node, StateContract $state): bool
    {
        return $this->interrupts->shouldInterrupt($node, $state);
    }

    public function handleInterrupt(
        string $executionId,
        string $graphName,
        string $nodeName,
        StateContract $state,
        ?StreamContext $streamContext = null
    ): StateContract {
        return $this->interrupts
            ->handle($executionId, $graphName, $nodeName, $state, $streamContext)
            ->state;
    }

    /**
     * @return string|array<int, string>|null
     */
    public function determineNext(Graph $graph, string $current, StateContract $state): string|array|null
    {
        return $this->routerResolver->determineNext($graph, $current, $state);
    }

    /**
     * @param  array<int, string>  $parallelNodes
     */
    public function determinePostParallelNext(Graph $graph, array $parallelNodes, StateContract $state): ?string
    {
        return $this->routerResolver->determinePostParallelNext($graph, $parallelNodes, $state);
    }

    public function executeFromNode(
        Graph $graph,
        StateContract $state,
        string $startNode,
        string $executionId,
        bool $useBatch = false,
        ?LoopMetadata $loopMetadata = null
    ): StateContract {
        $scope = $this->enterScope($executionId, $graph, new ExecutionOptions, $loopMetadata);
        $traceContext = $scope->traceContext;

        $current = $startNode;
        $visited = [];
        $maxIterations = config('agenticgraph.execution.max_iterations', 1000);
        $iterations = 0;

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

                    $currentStatus = $this->repo->getStatus($executionId);
                    if ($currentStatus === 'queued' || $currentStatus === 'interrupted') {
                        $this->repo->started($executionId, $graph->name, State::toStoragePayload($state));
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
                        if ($useBatch) {
                            $this->parallelExecutor->executeParallelViaBatch(
                                $graph,
                                $next,
                                $state,
                                $executionId
                            );

                            $traceContext->endSpan();
                            $this->contextManager->exit($scope);

                            return $state;
                        }
                        $result = $this->parallelExecutor->executeParallel(
                            $graph,
                            $next,
                            $state,
                            $executionId
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
            } while (true);

            $this->repo->completed($executionId, State::toStoragePayload($state), 0.0);
            $completedAt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true))) ?: new \DateTimeImmutable;
            $this->events->dispatch(new GraphExecutionCompleted($executionId, $graph->name, $state, $completedAt, 0.0, []));

            $exitState = $state->hasInterrupt() ? null : $state;
            $this->contextManager->exit($scope, $exitState);

            return $state;
        } catch (\Throwable $e) {
            $this->repo->failed($executionId, $e->getMessage());
            $this->contextManager->exitWithException($scope, $e);
            throw $e;
        }
    }

    /**
     * @return array{executionId: string, checkpointId: string|null}
     */
    public function executeUntil(Graph $graph, StateContract $state, callable $predicate): array
    {
        $state->validate();

        $executionId = $this->generateExecutionId();
        $this->repo->queued($executionId, $graph->name, State::toStoragePayload($state));
        $this->repo->started($executionId, $graph->name, State::toStoragePayload($state));

        $scope = $this->enterScope($executionId, $graph, new ExecutionOptions);
        $traceContext = $scope->traceContext;
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
                    $next = $this->determineNext($graph, $current, $state);
                    if ($predicate($state)) {
                        $nextNode = is_array($next) ? ($next[0] ?? $current) : ($next ?? $current);
                        $checkpoint = new Checkpoint;
                        $checkpoint->graphName = $graph->name;
                        $checkpoint->executionId = $executionId;
                        $checkpoint->state = $state;
                        $checkpoint->currentNode = $nextNode;
                        $checkpoint->createdAt = new \DateTimeImmutable;
                        $lastCheckpointId = $this->checkpoints->save($executionId, $checkpoint);
                        $traceContext->endSpan();
                        break;
                    }
                    if ($next === null) {
                        $traceContext->endSpan();
                        break;
                    }
                    if (is_array($next)) {
                        $result = $this->parallelExecutor->executeParallel(
                            $graph,
                            $next,
                            $state,
                            $executionId
                        );
                        $state = $result->state;
                        $current = $result->nextNode;
                    } else {
                        $current = $next;
                    }
                    $traceContext->endSpan();
                } finally {
                    $lock->release();
                }
            }

            $this->contextManager->exit($scope);

            return ['executionId' => $executionId, 'checkpointId' => $lastCheckpointId];
        } catch (\Throwable $e) {
            $this->repo->failed($executionId, $e->getMessage());
            $this->contextManager->exitWithException($scope, $e);
            throw $e;
        }
    }

    public function checkpoint(Graph $graph, string $executionId, string $currentNode, StateContract $state, ?ExecutionContext $executionContext = null): void
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

        if ($executionContext !== null) {
            $loopMetadata = LoopMetadata::fromExecutionContext($executionContext);
            $checkpoint->metadata['loop'] = $loopMetadata->toArray();
        }
        $streamContext = $this->streamingCoordinator->streamContext();
        if ($streamContext) {
            $session = $streamContext->session();
            $checkpoint->metadata['stream_id'] = $session->streamId;
            $checkpoint->metadata['last_seq'] = $session->seq;
            $checkpoint->metadata['stream_cursor'] = $session->seq;
        }

        $this->checkpoints->save($executionId, $checkpoint);
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
    public function executeNode(Graph $graph, string $executionId, string $nodeName, StateContract $state, ?string $parentSpanId = null): array
    {
        $traceContext = $this->container->make(TraceContext::class);
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

    private function runNodeWithMiddleware(StateContract $state, NodeInterface $node, string $executionId, string $nodeName, ?string $spanId): StateContract
    {
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
                        $traceContext = $this->container->make(TraceContext::class);
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
                            $traceContext->getParentSpanId()
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

    public function streamContext(): ?StreamContext
    {
        return $this->streamingCoordinator->streamContext();
    }

    private function generateExecutionId(): string
    {
        $prefix = 'exec_';
        $timestamp = microtime(true);
        $random = bin2hex(random_bytes(8));

        return $prefix.$timestamp.'_'.$random;
    }

    private function enterScope(
        string $executionId,
        Graph $graph,
        ExecutionOptions $options,
        ?LoopMetadata $loopMetadata = null
    ): ExecutionScope {
        if ($this->container->bound(TraceContext::class)) {
            $traceContext = $this->container->make(TraceContext::class);

            return $this->contextManager->enterWithTrace(
                $executionId,
                $graph->name,
                $options,
                $traceContext,
                $loopMetadata
            );
        }

        return $this->contextManager->enter($executionId, $graph->name, $options, $loopMetadata);
    }
}
