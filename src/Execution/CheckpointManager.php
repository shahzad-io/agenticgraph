<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Events\CheckpointCreated;

class CheckpointManager
{
    private StorageInterface $storage;

    private ?DatabaseManager $db;

    private ?Dispatcher $events;

    private int $checkpointFrequency;

    private int $maxStateSize;

    /**
     * Node execution counters per execution ID.
     *
     * @var array<string, int>
     */
    private array $nodeCounters = [];

    public function __construct(
        StorageInterface $storage,
        ?DatabaseManager $db = null,
        ?Dispatcher $events = null,
        int $checkpointFrequency = 1,
        int $maxStateSize = 10485760 // 10MB default
    ) {
        $this->storage = $storage;
        $this->db = $db;
        $this->events = $events;
        $this->checkpointFrequency = $checkpointFrequency;
        $this->maxStateSize = $maxStateSize;
    }

    /**
     * Save a checkpoint with atomic operations and frequency control.
     *
     * @throws \RuntimeException If state size exceeds maximum
     */
    public function save(string $executionId, Checkpoint $checkpoint): ?string
    {
        // Increment node counter for this execution
        if (! isset($this->nodeCounters[$executionId])) {
            $this->nodeCounters[$executionId] = 0;
        }
        $this->nodeCounters[$executionId]++;
        $nodeCount = $this->nodeCounters[$executionId];

        // Check checkpoint frequency - skip if not on frequency boundary
        if ($this->checkpointFrequency > 1 && $nodeCount % $this->checkpointFrequency !== 0) {
            return null;
        }

        // Set checkpoint metadata
        $checkpoint->executionId = $executionId;
        if (! isset($checkpoint->id) || $checkpoint->id === '') {
            $checkpoint->id = Str::uuid()->toString();
        }
        $checkpoint->createdAt = new \DateTimeImmutable;

        // Validate state size before saving
        $stateSize = $this->calculateStateSize($checkpoint);
        if ($stateSize > $this->maxStateSize) {
            throw new \RuntimeException(
                sprintf(
                    'State size (%d bytes) exceeds maximum allowed size (%d bytes) for execution %s',
                    $stateSize,
                    $this->maxStateSize,
                    $executionId
                )
            );
        }

        // Perform atomic save with optional transaction and event dispatch
        return $this->performAtomicSave($executionId, $checkpoint);
    }

    /**
     * Perform atomic checkpoint save with transaction and event.
     */
    private function performAtomicSave(string $executionId, Checkpoint $checkpoint): string
    {
        // If we have database and events, wrap in transaction
        if ($this->db !== null && $this->events !== null) {
            return $this->db->transaction(function () use ($executionId, $checkpoint) {
                $id = $this->storage->save($checkpoint);

                $this->events->dispatch(new CheckpointCreated(
                    executionId: $executionId,
                    checkpointId: $id,
                    nodeName: $checkpoint->currentNode,
                    createdAt: $checkpoint->createdAt
                ));

                return $id;
            });
        }

        // Otherwise, just save without transaction
        $id = $this->storage->save($checkpoint);

        if ($this->events !== null) {
            $this->events->dispatch(new CheckpointCreated(
                executionId: $executionId,
                checkpointId: $id,
                nodeName: $checkpoint->currentNode,
                createdAt: $checkpoint->createdAt
            ));
        }

        return $id;
    }

    /**
     * Calculate the serialized size of checkpoint state.
     */
    private function calculateStateSize(Checkpoint $checkpoint): int
    {
        try {
            $serialized = serialize($checkpoint->state->toArray());

            return strlen($serialized);
        } catch (\Throwable $e) {
            // If serialization fails, estimate using JSON
            $json = json_encode($checkpoint->state->toArray());

            return $json !== false ? strlen($json) : 0;
        }
    }

    /**
     * Reset the node counter for an execution.
     */
    public function resetCounter(string $executionId): void
    {
        unset($this->nodeCounters[$executionId]);
    }

    /**
     * Get the current node count for an execution.
     */
    public function getNodeCount(string $executionId): int
    {
        return $this->nodeCounters[$executionId] ?? 0;
    }

    /**
     * Load a checkpoint by ID.
     */
    public function load(string $checkpointId): ?Checkpoint
    {
        return $this->storage->load($checkpointId);
    }

    /**
     * Delete a checkpoint by ID.
     */
    public function delete(string $checkpointId): bool
    {
        return $this->storage->delete($checkpointId);
    }

    /**
     * List checkpoints for a graph.
     *
     * @return array<int, array{id: string, execution_id: string, current_node: string, created_at: mixed}>
     */
    public function list(string $graphName, int $limit = 50, int $offset = 0): array
    {
        return $this->storage->list($graphName, $limit, $offset);
    }

    /**
     * Cleanup old checkpoints.
     */
    public function cleanup(int $olderThanDays = 30): int
    {
        return $this->storage->cleanup($olderThanDays);
    }

    /**
     * Get the storage instance.
     */
    public function getStorage(): StorageInterface
    {
        return $this->storage;
    }

    /**
     * Get the checkpoint frequency.
     */
    public function getCheckpointFrequency(): int
    {
        return $this->checkpointFrequency;
    }

    /**
     * Get the maximum state size.
     */
    public function getMaxStateSize(): int
    {
        return $this->maxStateSize;
    }
}
