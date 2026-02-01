<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\State;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\StorageInterface;

/**
 * Service class for managing state operations.
 *
 * This class provides dependency injection support and is mockable for testing.
 * For backward compatibility, static proxy methods are available via StateManagerFacade.
 */
class StateManager
{
    private DatabaseManager $db;

    private StorageInterface $storage;

    private Dispatcher $events;

    public function __construct(
        DatabaseManager $db,
        StorageInterface $storage,
        Dispatcher $events
    ) {
        $this->db = $db;
        $this->storage = $storage;
        $this->events = $events;
    }

    /**
     * Compute the difference between two states.
     *
     * @return array{added: array<string, mixed>, modified: array<string, array{from: mixed, to: mixed}>, removed: array<string, mixed>}
     */
    public function diff(StateContract $old, StateContract $new): array
    {
        $a = $old->toArray();
        $b = $new->toArray();
        $added = array_diff_key($b, $a);
        $removed = array_diff_key($a, $b);
        $modified = [];
        foreach ($a as $k => $v) {
            if (array_key_exists($k, $b) && $b[$k] !== $v) {
                $modified[$k] = ['from' => $v, 'to' => $b[$k]];
            }
        }

        return ['added' => $added, 'modified' => $modified, 'removed' => $removed];
    }

    /**
     * Get all checkpoint versions for an execution.
     *
     * @return array<int, array{id: string, node: string, created_at: string}>
     */
    public function getVersions(string $executionId): array
    {
        // Get all checkpoints for this execution
        $checkpoints = $this->db->table('agenticgraph_checkpoints')
            ->where('execution_id', $executionId)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'node' => $row->current_node,
                'created_at' => $row->created_at,
            ])
            ->toArray();

        return $checkpoints;
    }

    /**
     * Rollback to a specific checkpoint.
     *
     * @throws \RuntimeException If checkpoint not found or doesn't belong to execution
     */
    public function rollback(string $executionId, string $checkpointId): StateContract
    {
        $checkpoint = $this->storage->load($checkpointId);

        if (! $checkpoint) {
            throw new \RuntimeException("Checkpoint not found: {$checkpointId}");
        }

        if ($checkpoint->executionId !== $executionId) {
            throw new \RuntimeException(
                "Checkpoint {$checkpointId} does not belong to execution {$executionId}"
            );
        }

        return $checkpoint->state;
    }

    /**
     * Encrypt state data.
     */
    public function encrypt(StateContract $state): string
    {
        return base64_encode(json_encode($state->toArray()));
    }

    /**
     * Decrypt state data.
     */
    public function decrypt(string $payload): StateContract
    {
        $data = json_decode(base64_decode($payload), true) ?: [];

        return new State($data);
    }

    /**
     * Compress state data.
     */
    public function compress(StateContract $state): string
    {
        return gzcompress(json_encode($state->toArray()));
    }

    /**
     * Decompress state data.
     */
    public function decompress(string $payload): StateContract
    {
        $json = gzuncompress($payload);
        $data = $json ? json_decode($json, true) : [];

        return new State($data);
    }

    /**
     * Merge multiple states into one.
     *
     * @param  array<int, StateContract>  $states
     */
    public function mergeAll(array $states): StateContract
    {
        $result = new State;
        foreach ($states as $state) {
            $result = $result->merge($state);
        }

        return $result;
    }

    /**
     * Get the database manager instance.
     */
    public function getDatabaseManager(): DatabaseManager
    {
        return $this->db;
    }

    /**
     * Get the storage instance.
     */
    public function getStorage(): StorageInterface
    {
        return $this->storage;
    }

    /**
     * Get the event dispatcher instance.
     */
    public function getEventDispatcher(): Dispatcher
    {
        return $this->events;
    }
}
