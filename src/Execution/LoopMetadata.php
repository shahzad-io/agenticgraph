<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

/**
 * Typed structure for checkpoint metadata loop data.
 * Immutable readonly class for Octane safety.
 */
readonly class LoopMetadata
{
    /**
     * @param  int  $globalIterations  Total iterations across all nodes
     * @param  array<int, string>  $pathStack  Current execution path stack
     * @param  array<string, int>  $nodeVisitCounts  Visit count per node
     * @param  array<string, int>  $cycleCompletionCounts  Completion count per cycle hash
     * @param  array<string, array<int, int>>  $nodeIndexMap  Map of node to indices in path stack
     * @param  float  $startTime  Execution start time (microtime)
     */
    public function __construct(
        public int $globalIterations,
        public array $pathStack,
        public array $nodeVisitCounts,
        public array $cycleCompletionCounts,
        public array $nodeIndexMap,
        public float $startTime,
    ) {}

    /**
     * Convert to array for checkpoint serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'global_iterations' => $this->globalIterations,
            'path_stack' => $this->pathStack,
            'node_visit_counts' => $this->nodeVisitCounts,
            'cycle_completion_counts' => $this->cycleCompletionCounts,
            'node_index_map' => $this->nodeIndexMap,
            'start_time' => $this->startTime,
        ];
    }

    /**
     * Create from array (checkpoint deserialization).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            globalIterations: $data['global_iterations'] ?? 0,
            pathStack: $data['path_stack'] ?? [],
            nodeVisitCounts: $data['node_visit_counts'] ?? [],
            cycleCompletionCounts: $data['cycle_completion_counts'] ?? [],
            nodeIndexMap: $data['node_index_map'] ?? [],
            startTime: $data['start_time'] ?? microtime(true),
        );
    }

    /**
     * Create from ExecutionContext for checkpoint persistence.
     */
    public static function fromExecutionContext(ExecutionContext $context): self
    {
        return new self(
            globalIterations: $context->globalIterations,
            pathStack: $context->pathStack,
            nodeVisitCounts: $context->nodeVisitCounts,
            cycleCompletionCounts: $context->cycleCompletionCounts,
            nodeIndexMap: $context->nodeIndexMap,
            startTime: $context->startTime,
        );
    }
}
