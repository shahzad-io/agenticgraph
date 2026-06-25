<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

/**
 * Read-only facade over ExecutionContext for low-floor loop-aware routing.
 * Hides nodeIndexMap, cycleCompletionCounts hashes, executionId, and startTime.
 */
readonly class LoopContext
{
    public function __construct(private ExecutionContext $context) {}

    public static function from(ExecutionContext $context): self
    {
        return new self($context);
    }

    public function visitCount(string $node): int
    {
        return $this->context->getNodeVisitCount($node);
    }

    public function hasVisited(string $node): bool
    {
        return $this->visitCount($node) > 0;
    }

    public function globalIterations(): int
    {
        return $this->context->globalIterations;
    }

    public function elapsedSeconds(): float
    {
        return $this->context->getElapsedTime();
    }

    public function pathDepth(): int
    {
        return count($this->context->pathStack);
    }

    /**
     * @return array<int, string>
     */
    public function recentPath(int $limit = 10): array
    {
        if ($limit <= 0) {
            return [];
        }

        return array_slice($this->context->pathStack, -$limit);
    }

    /**
     * @param  array<int, string>  $nodes
     * @return array<string, int>
     */
    public function visitCountsFor(array $nodes): array
    {
        $counts = [];
        foreach ($nodes as $node) {
            $counts[$node] = $this->visitCount($node);
        }

        return $counts;
    }

    /**
     * @param  array<int, string>  $nodes
     * @return array<int, string>
     */
    public function unvisitedAmong(array $nodes): array
    {
        return array_values(array_filter(
            $nodes,
            fn (string $node): bool => ! $this->hasVisited($node)
        ));
    }

    /**
     * @param  array<int, string>  $nodes
     */
    public function mostVisitedAmong(array $nodes): ?string
    {
        if ($nodes === []) {
            return null;
        }

        $mostVisited = null;
        $highestCount = 0;

        foreach ($nodes as $node) {
            $count = $this->visitCount($node);
            if ($count > $highestCount) {
                $highestCount = $count;
                $mostVisited = $node;
            }
        }

        return $highestCount > 0 ? $mostVisited : null;
    }
}
