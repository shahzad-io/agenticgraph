<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

use Shahzad\AgenticGraph\Execution\ParallelResult;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Streaming\StreamContext;

interface ParallelExecutorInterface
{
    /**
     * @param  array<int, string>  $parallelNodes
     */
    public function executeParallel(
        Graph $graph,
        array $parallelNodes,
        StateContract $state,
        string $executionId,
        ?StreamContext $streamContext = null
    ): ParallelResult;

    /**
     * @param  array<int, string>  $parallelNodes
     */
    public function executeParallelViaBatch(
        Graph $graph,
        array $parallelNodes,
        StateContract $state,
        string $executionId
    ): ParallelResult;
}
