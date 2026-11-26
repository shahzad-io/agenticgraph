<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

use Shahzad\AgenticGraph\Execution\InterruptResult;
use Shahzad\AgenticGraph\Streaming\StreamContext;

interface InterruptServiceInterface
{
    public function shouldInterrupt(NodeInterface $node, StateContract $state): bool;

    public function handle(
        string $executionId,
        string $graphName,
        string $nodeName,
        StateContract $state,
        ?StreamContext $streamContext = null
    ): InterruptResult;

    public function resume(string $checkpointId): StateContract;

    public function resumeWithInput(string $checkpointId, mixed $humanInput): StateContract;
}
