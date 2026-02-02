<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

/**
 * Tracks loop state for a single graph execution.
 * Immutable readonly class for Octane safety.
 * Bound to container per-execution scope.
 */
readonly class ExecutionContext
{
    /**
     * @param  string  $executionId  Unique execution identifier
     * @param  float  $startTime  Execution start time (microtime)
     * @param  int  $globalIterations  Total iterations across all nodes
     * @param  array<int, string>  $pathStack  Current execution path (node visit order)
     * @param  array<string, int>  $nodeVisitCounts  How many times each node was visited
     * @param  array<string, int>  $cycleCompletionCounts  How many times each cycle completed
     * @param  array<string, array<int, int>>  $nodeIndexMap  Map of node to indices in path stack
     */
    public function __construct(
        public string $executionId,
        public float $startTime,
        public int $globalIterations = 0,
        public array $pathStack = [],
        public array $nodeVisitCounts = [],
        public array $cycleCompletionCounts = [],
        public array $nodeIndexMap = [],
    ) {}

    /**
     * Increment global iteration counter.
     * Returns new immutable instance.
     */
    public function incrementIteration(): self
    {
        return new self(
            executionId: $this->executionId,
            startTime: $this->startTime,
            globalIterations: $this->globalIterations + 1,
            pathStack: $this->pathStack,
            nodeVisitCounts: $this->nodeVisitCounts,
            cycleCompletionCounts: $this->cycleCompletionCounts,
            nodeIndexMap: $this->nodeIndexMap,
        );
    }

    /**
     * Push a node onto the path stack and update tracking.
     * Returns new immutable instance.
     */
    public function pushNode(string $node): self
    {
        $newPathStack = array_merge($this->pathStack, [$node]);
        $newNodeVisitCounts = $this->nodeVisitCounts;
        $newNodeVisitCounts[$node] = ($newNodeVisitCounts[$node] ?? 0) + 1;

        $newNodeIndexMap = $this->nodeIndexMap;
        if (! isset($newNodeIndexMap[$node])) {
            $newNodeIndexMap[$node] = [];
        }
        $newNodeIndexMap[$node][] = count($newPathStack) - 1;

        return new self(
            executionId: $this->executionId,
            startTime: $this->startTime,
            globalIterations: $this->globalIterations,
            pathStack: $newPathStack,
            nodeVisitCounts: $newNodeVisitCounts,
            cycleCompletionCounts: $this->cycleCompletionCounts,
            nodeIndexMap: $newNodeIndexMap,
        );
    }

    /**
     * Detect if visiting nextNode would complete a cycle.
     * Returns tuple of [newContext, CycleDetection] if cycle found, [this, null] otherwise.
     *
     * @return array{0: self, 1: CycleDetection|null}
     */
    public function detectAndRecordCycle(string $nextNode): array
    {
        // Check if nextNode exists in nodeIndexMap (has been visited before)
        if (! isset($this->nodeIndexMap[$nextNode])) {
            return [$this, null];
        }

        // Get the last occurrence index
        $indices = $this->nodeIndexMap[$nextNode];
        $cycleStartIndex = end($indices);

        // Extract cycle path: from cycleStartIndex to end of current stack + nextNode
        $cyclePath = array_slice($this->pathStack, $cycleStartIndex);
        $cyclePath[] = $nextNode;

        // Generate hash: A→B→C→A
        $cycleHash = implode('→', $cyclePath);

        // Increment cycle completion count
        $newCycleCompletionCounts = $this->cycleCompletionCounts;
        $newCycleCompletionCounts[$cycleHash] = ($newCycleCompletionCounts[$cycleHash] ?? 0) + 1;

        $cycleDetection = new CycleDetection(
            cycleHash: $cycleHash,
            cyclePath: $cyclePath,
            completionCount: $newCycleCompletionCounts[$cycleHash],
            cycleStartIndex: $cycleStartIndex,
        );

        // Return new context with updated cycle counts
        $newContext = new self(
            executionId: $this->executionId,
            startTime: $this->startTime,
            globalIterations: $this->globalIterations,
            pathStack: $this->pathStack,
            nodeVisitCounts: $this->nodeVisitCounts,
            cycleCompletionCounts: $newCycleCompletionCounts,
            nodeIndexMap: $this->nodeIndexMap,
        );

        return [$newContext, $cycleDetection];
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'execution_id' => $this->executionId,
            'start_time' => $this->startTime,
            'global_iterations' => $this->globalIterations,
            'path_stack' => $this->pathStack,
            'node_visit_counts' => $this->nodeVisitCounts,
            'cycle_completion_counts' => $this->cycleCompletionCounts,
            'node_index_map' => $this->nodeIndexMap,
        ];
    }

    /**
     * Create from array (for deserialization).
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $executionId  Override execution ID (for resume)
     */
    public static function fromArray(array $data, ?string $executionId = null): self
    {
        return new self(
            executionId: $executionId ?? $data['execution_id'] ?? '',
            startTime: $data['start_time'] ?? microtime(true),
            globalIterations: $data['global_iterations'] ?? 0,
            pathStack: $data['path_stack'] ?? [],
            nodeVisitCounts: $data['node_visit_counts'] ?? [],
            cycleCompletionCounts: $data['cycle_completion_counts'] ?? [],
            nodeIndexMap: $data['node_index_map'] ?? [],
        );
    }

    /**
     * Get elapsed time in seconds since execution started.
     */
    public function getElapsedTime(): float
    {
        return microtime(true) - $this->startTime;
    }

    /**
     * Get visit count for a specific node.
     */
    public function getNodeVisitCount(string $node): int
    {
        return $this->nodeVisitCounts[$node] ?? 0;
    }

    /**
     * Get cycle completion count for a specific cycle hash.
     */
    public function getCycleCompletionCount(string $cycleHash): int
    {
        return $this->cycleCompletionCounts[$cycleHash] ?? 0;
    }
}
