<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Graph\Graph;

interface ExecutionResumer
{
    public function __invoke(
        Graph $graph,
        StateContract $state,
        string $startNode,
        string $executionId,
        bool $useBatch = false,
        ?LoopMetadata $loopMetadata = null
    ): StateContract;
}
