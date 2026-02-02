<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

/**
 * Value object representing a detected cycle completion.
 * Immutable readonly class for Octane safety.
 */
readonly class CycleDetection
{
    /**
     * @param  string  $cycleHash  Unique hash of the cycle (e.g., 'A→B→A')
     * @param  array<int, string>  $cyclePath  Array of node names in the cycle path
     * @param  int  $completionCount  How many times this cycle has completed
     * @param  int  $cycleStartIndex  Index in path stack where cycle started
     */
    public function __construct(
        public string $cycleHash,
        public array $cyclePath,
        public int $completionCount,
        public int $cycleStartIndex,
    ) {}

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cycle_hash' => $this->cycleHash,
            'cycle_path' => $this->cyclePath,
            'completion_count' => $this->completionCount,
            'cycle_start_index' => $this->cycleStartIndex,
        ];
    }

    /**
     * Create from array (for deserialization).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            cycleHash: $data['cycle_hash'] ?? '',
            cyclePath: $data['cycle_path'] ?? [],
            completionCount: $data['completion_count'] ?? 0,
            cycleStartIndex: $data['cycle_start_index'] ?? 0,
        );
    }
}
