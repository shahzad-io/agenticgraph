<?php

namespace Shahzad\AgenticGraph\State;

use Shahzad\AgenticGraph\Contracts\StateContract;

class StateManager
{
    public static function diff(StateContract $old, StateContract $new): array
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

    public static function getVersions(string $executionId): array
    {
        // Get all checkpoints for this execution
        $checkpoints = \Illuminate\Support\Facades\DB::table('agenticgraph_checkpoints')
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

    public static function rollback(string $executionId, string $checkpointId): StateContract
    {
        $storage = match (config('agenticgraph.storage')) {
            'redis' => app(\Shahzad\AgenticGraph\Storage\RedisStorage::class),
            'file' => app(\Shahzad\AgenticGraph\Storage\FileStorage::class),
            default => app(\Shahzad\AgenticGraph\Storage\DatabaseStorage::class),
        };

        $checkpoint = $storage->load($checkpointId);

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

    public static function encrypt(StateContract $state): string
    {
        return base64_encode(json_encode($state->toArray()));
    }

    public static function decrypt(string $payload): StateContract
    {
        $data = json_decode(base64_decode($payload), true) ?: [];

        return new State($data);
    }

    public static function compress(StateContract $state): string
    {
        return gzcompress(json_encode($state->toArray()));
    }

    public static function decompress(string $payload): StateContract
    {
        $json = gzuncompress($payload);
        $data = $json ? json_decode($json, true) : [];

        return new State($data);
    }

    public static function mergeAll(array $states): StateContract
    {
        $result = new State;
        foreach ($states as $state) {
            $result = $result->merge($state);
        }

        return $result;
    }
}
