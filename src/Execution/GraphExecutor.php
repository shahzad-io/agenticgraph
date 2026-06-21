<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\ParallelExecutorInterface;
use Shahzad\AgenticGraph\Contracts\RouterResolverInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Streaming\AsyncStreamCoordinator;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamHandle;
use Shahzad\AgenticGraph\Streaming\StreamingExecutionCoordinator;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumePayload;
use Shahzad\AgenticGraph\Tracing\Instrumentation\HttpClientInstrumentation;

class GraphExecutor implements ExecutionLoopDelegate
{
    private ExecutionCoordinator $coordinator;

    public function __construct(
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
        ?ExecutionContextManager $contextManager = null,
    ) {
        $this->coordinator = ExecutionCoordinator::fromContainer(
            $events,
            $cache,
            $logger,
            $repo,
            $routerResolver,
            $parallelExecutor,
            $streamingCoordinator,
            $asyncStreamCoordinator,
            $httpClientInstrumentation,
            $interrupts,
            $contextManager
        );
        $this->coordinator->setDelegate($this);
    }

    public function execute(Graph $graph, StateContract $state, bool $useBatch = false, ?string $executionId = null): StateContract
    {
        return $this->coordinator->execute($graph, $state, $useBatch, $executionId);
    }

    public function executeWithOptions(Graph $graph, StateContract $state, ExecutionOptions $options, ?string $executionId = null): ExecutionHandle
    {
        return $this->coordinator->executeWithOptions($graph, $state, $options, $executionId);
    }

    public function executeWithStreaming(string $name, StateContract $state, StreamOptions $options, ?string $executionId = null): StreamHandle
    {
        return $this->coordinator->executeWithStreaming($name, $state, $options, $executionId);
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
        return $this->coordinator->executeWithStreamingFromNode(
            $graph,
            $state,
            $startNode,
            $executionId,
            $options,
            $loopMetadata,
            $resumePayload
        );
    }

    public function resolveStreamingMode(ExecutionOptions $options): StreamingMode
    {
        return $this->coordinator->resolveStreamingMode($options);
    }

    /**
     * Check if a node should interrupt execution.
     */
    public function shouldInterrupt(NodeInterface $node, StateContract $state): bool
    {
        return $this->coordinator->shouldInterrupt($node, $state);
    }

    /**
     * Handle node interrupt by creating checkpoint and pausing execution.
     */
    public function handleInterrupt(
        string $executionId,
        string $graphName,
        string $nodeName,
        StateContract $state,
        ?StreamContext $streamContext = null
    ): StateContract {
        return $this->coordinator->handleInterrupt($executionId, $graphName, $nodeName, $state, $streamContext);
    }

    /**
     * @return string|array<int, string>|null
     */
    protected function determineNext(Graph $graph, string $current, StateContract $state): string|array|null
    {
        return $this->coordinator->determineNext($graph, $current, $state);
    }

    public function executeAsync(Graph $graph, StateContract $state): string
    {
        return $this->coordinator->executeAsync($graph, $state);
    }

    public function resume(string $checkpointId): StateContract
    {
        return $this->coordinator->resume($checkpointId);
    }

    /**
     * Resume execution from an interrupt checkpoint with human input.
     *
     * @param  string  $checkpointId  The checkpoint ID from the interrupt
     * @param  mixed  $humanInput  Human-provided input (typically array)
     * @return StateContract Final state
     *
     * @throws \InvalidArgumentException If checkpoint is not an interrupt checkpoint
     * @throws \RuntimeException If checkpoint or graph not found
     */
    public function resumeWithInput(string $checkpointId, mixed $humanInput): StateContract
    {
        return $this->coordinator->resumeWithInput($checkpointId, $humanInput);
    }

    public function executeFromNode(Graph $graph, StateContract $state, string $startNode, string $executionId, bool $useBatch = false, ?LoopMetadata $loopMetadata = null): StateContract
    {
        return $this->coordinator->executeFromNode($graph, $state, $startNode, $executionId, $useBatch, $loopMetadata);
    }

    /**
     * @return array{executionId: string, checkpointId: string|null}
     */
    public function executeUntil(Graph $graph, StateContract $state, callable $predicate): array
    {
        return $this->coordinator->executeUntil($graph, $state, $predicate);
    }

    public function checkpoint(Graph $graph, string $executionId, string $currentNode, StateContract $state, ?ExecutionContext $executionContext = null): void
    {
        $this->coordinator->checkpoint($graph, $executionId, $currentNode, $state, $executionContext);
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
        return $this->coordinator->executeNode($graph, $executionId, $nodeName, $state, $parentSpanId);
    }

    public function streamContext(): ?StreamContext
    {
        return $this->coordinator->streamContext();
    }
}
