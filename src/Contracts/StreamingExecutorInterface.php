<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

use Shahzad\AgenticGraph\Execution\LoopMetadata;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Streaming\StreamHandle;
use Shahzad\AgenticGraph\Streaming\StreamOptions;

interface StreamingExecutorInterface
{
    public function execute(
        Graph $graph,
        StateContract $state,
        StreamOptions $options,
        string $executionId
    ): StreamHandle;

    public function executeFromNode(
        Graph $graph,
        StateContract $state,
        string $startNode,
        StreamOptions $options,
        string $executionId,
        ?LoopMetadata $loopMetadata = null
    ): StreamHandle;
}
