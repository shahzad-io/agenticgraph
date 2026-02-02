<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

/**
 * Enforces loop limits (iteration, per-cycle, timeout).
 * Zero overhead when disabled.
 */
class LoopGuard
{
    /**
     * @param  int  $maxGlobalIterations  Maximum total iterations across all nodes
     * @param  int|null  $maxCycleIterations  Maximum iterations per specific cycle
     * @param  int|null  $timeoutSeconds  Maximum execution time in seconds
     * @param  bool  $enabled  Whether loop guards are enabled
     */
    public function __construct(
        private readonly int $maxGlobalIterations,
        private readonly ?int $maxCycleIterations,
        private readonly ?int $timeoutSeconds,
        private readonly bool $enabled,
    ) {}

    /**
     * Check all loop limits.
     * No-op if disabled.
     *
     * @throws LoopLimitExceededException
     */
    public function check(ExecutionContext $context, ?CycleDetection $cycle = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->checkGlobalIterations($context);
        $this->checkTimeout($context);

        if ($cycle !== null) {
            $this->checkCycleIterations($cycle);
        }
    }

    /**
     * Check global iteration limit.
     *
     * @throws LoopLimitExceededException
     */
    private function checkGlobalIterations(ExecutionContext $context): void
    {
        if ($context->globalIterations >= $this->maxGlobalIterations) {
            throw new LoopLimitExceededException(
                message: "Graph execution exceeded maximum iterations ({$this->maxGlobalIterations}). ".
                         'Possible infinite loop detected. '.
                         'Path: '.implode('→', $context->pathStack),
                limitType: 'global',
                limitValue: $this->maxGlobalIterations,
                currentValue: $context->globalIterations,
                pathStack: $context->pathStack,
            );
        }
    }

    /**
     * Check per-cycle iteration limit.
     *
     * @throws LoopLimitExceededException
     */
    private function checkCycleIterations(CycleDetection $cycle): void
    {
        if ($this->maxCycleIterations === null) {
            return;
        }

        if ($cycle->completionCount >= $this->maxCycleIterations) {
            throw new LoopLimitExceededException(
                message: "Cycle '{$cycle->cycleHash}' exceeded maximum iterations ({$this->maxCycleIterations}). ".
                         'Path: '.implode('→', $cycle->cyclePath),
                limitType: 'cycle',
                limitValue: $this->maxCycleIterations,
                currentValue: $cycle->completionCount,
                pathStack: $cycle->cyclePath,
                cycleHash: $cycle->cycleHash,
            );
        }
    }

    /**
     * Check timeout limit.
     *
     * @throws LoopLimitExceededException
     */
    private function checkTimeout(ExecutionContext $context): void
    {
        if ($this->timeoutSeconds === null) {
            return;
        }

        $elapsed = $context->getElapsedTime();
        if ($elapsed >= $this->timeoutSeconds) {
            throw new LoopLimitExceededException(
                message: "Graph execution exceeded timeout ({$this->timeoutSeconds} seconds). ".
                         'Elapsed: '.round($elapsed, 2).' seconds. '.
                         'Path: '.implode('→', $context->pathStack),
                limitType: 'timeout',
                limitValue: $this->timeoutSeconds,
                currentValue: (int) $elapsed,
                pathStack: $context->pathStack,
            );
        }
    }

    /**
     * Check if loop guards are enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
