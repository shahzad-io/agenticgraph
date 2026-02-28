<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Streaming\StreamContext;

interface ExecutionLoopDelegate
{
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
    public function executeNode(
        Graph $graph,
        string $executionId,
        string $nodeName,
        StateContract $state,
        ?string $parentSpanId = null
    ): array;

    public function checkpoint(
        Graph $graph,
        string $executionId,
        string $currentNode,
        StateContract $state,
        ?ExecutionContext $executionContext = null
    ): void;

    public function shouldInterrupt(NodeInterface $node, StateContract $state): bool;

    public function handleInterrupt(
        string $executionId,
        string $graphName,
        string $nodeName,
        StateContract $state,
        ?StreamContext $streamContext = null
    ): StateContract;
}
