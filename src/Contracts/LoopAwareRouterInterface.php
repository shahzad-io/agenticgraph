<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

use Shahzad\AgenticGraph\Execution\ExecutionContext;

/**
 * Extended router interface that receives loop context.
 * Backward compatible - extends RouterInterface.
 */
interface LoopAwareRouterInterface extends RouterInterface
{
    /**
     * Route with loop context awareness.
     * Implementations can check $context->globalIterations,
     * $context->cycleCompletionCounts, etc. to make loop-aware decisions.
     *
     * @return string|array<int, string>
     */
    public function routeWithContext(StateContract $state, ExecutionContext $context): string|array;
}
