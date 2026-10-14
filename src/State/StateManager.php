<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\State;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
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
        return $this->storage->listForExecution($executionId);
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
     * Detect keys written by multiple parallel branches with divergent values.
     *
     * Compares each branch's changes relative to the pre-fan-out state so unchanged
     * pre-existing keys do not false-positive.
     *
     * @param  array<int, string>  $branchNames
     * @param  array<int, StateContract>  $branchResults
     * @return array<int, array{key: string, branch_values: array<string, mixed>}>
     */
    public function detectParallelMergeCollisions(
        StateContract $baseState,
        array $branchNames,
        array $branchResults,
    ): array {
        if (count($branchNames) !== count($branchResults)) {
            throw new \InvalidArgumentException('Branch names and result states must align by index.');
        }

        /** @var array<string, array<string, mixed>> $writesByKey */
        $writesByKey = [];

        foreach ($branchNames as $index => $branchName) {
            $diff = $this->diff($baseState, $branchResults[$index]);

            foreach ($diff['added'] as $key => $value) {
                $writesByKey[$key][$branchName] = $value;
            }

            foreach ($diff['modified'] as $key => $change) {
                $writesByKey[$key][$branchName] = $change['to'];
            }
        }

        $collisions = [];

        foreach ($writesByKey as $key => $branchValues) {
            if (count($branchValues) < 2) {
                continue;
            }

            $distinctValues = [];
            foreach ($branchValues as $branchName => $value) {
                $fingerprint = $this->valueFingerprint($value);
                $distinctValues[$fingerprint] ??= [];
                $distinctValues[$fingerprint][] = $branchName;
            }

            if (count($distinctValues) > 1) {
                $collisions[] = [
                    'key' => $key,
                    'branch_values' => $branchValues,
                ];
            }
        }

        return $collisions;
    }

    /**
     * Log warnings for parallel merge key collisions (observational only; merge unchanged).
     *
     * @param  array<int, string>  $branchNames
     * @param  array<int, StateContract>  $branchResults
     */
    public function warnParallelMergeCollisions(
        LoggerInterface $logger,
        StateContract $baseState,
        array $branchNames,
        array $branchResults,
    ): void {
        if (! (bool) config('agenticgraph.logging.enabled', true)) {
            return;
        }

        $collisions = $this->detectParallelMergeCollisions($baseState, $branchNames, $branchResults);

        foreach ($collisions as $collision) {
            $branchValues = $collision['branch_values'];
            $keptBranch = null;

            foreach (array_reverse($branchNames) as $branchName) {
                if (array_key_exists($branchName, $branchValues)) {
                    $keptBranch = $branchName;
                    break;
                }
            }

            $logger->warning(
                'Parallel branch merge wrote the same state key with different values; last-write-wins kept one branch. '
                .'Use unique keys per parallel branch to avoid silent data loss.',
                [
                    'key' => $collision['key'],
                    'kept_branch' => $keptBranch,
                    'colliding_branches' => array_keys($branchValues),
                ]
            );
        }
    }

    private function valueFingerprint(mixed $value): string
    {
        if (is_array($value)) {
            return 'array:'.json_encode($value, JSON_THROW_ON_ERROR);
        }

        return get_debug_type($value).':'.var_export($value, true);
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
