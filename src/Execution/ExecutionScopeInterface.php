<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Graph\Graph;

interface ExecutionScopeInterface
{
    public function enter(
        string $executionId,
        string $graphName,
        ExecutionOptions $options,
        ?LoopMetadata $loopMetadata = null,
        ?Graph $graph = null
    ): ExecutionScope;

    public function exit(ExecutionScope $scope, ?StateContract $finalState = null): void;

    public function exitWithException(ExecutionScope $scope, \Throwable $e): void;
}
