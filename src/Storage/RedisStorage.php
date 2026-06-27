<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Storage;

use Illuminate\Support\Facades\Redis;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Support\CheckpointVersionOrdering;

class RedisStorage implements StorageInterface
{
    protected string $prefix = 'agenticgraph:checkpoint:';

    public function save(Checkpoint $checkpoint): string
    {
        $key = $this->prefix.$checkpoint->id;
        $payload = json_encode([
            'id' => $checkpoint->id,
            'graph_name' => $checkpoint->graphName,
            'execution_id' => $checkpoint->executionId,
            'state' => $checkpoint->state->toArray(),
            'current_node' => $checkpoint->currentNode,
            'metadata' => $checkpoint->metadata,
            'created_at' => $checkpoint->createdAt->format('c'),
        ]);
        Redis::set($key, $payload);
        $ttl = (int) config('agenticgraph.checkpoint.ttl', 7) * 86400;
        if ($ttl > 0) {
            Redis::expire($key, $ttl);
        }

        $sequence = (int) ($checkpoint->metadata['checkpoint_sequence'] ?? 0);
        $score = $sequence > 0 ? $sequence : microtime(true);
        Redis::zadd($this->executionIndexKey($checkpoint->executionId), $score, $checkpoint->id);
        if ($ttl > 0) {
            Redis::expire($this->executionIndexKey($checkpoint->executionId), $ttl);
        }

        return $checkpoint->id;
    }

    public function load(string $id): ?Checkpoint
    {
        $payload = Redis::get($this->prefix.$id);
        if (! $payload) {
            return null;
        }
        $data = json_decode($payload, true) ?? [];
        $c = new Checkpoint;
        $c->id = $data['id'] ?? $id;
        $c->graphName = $data['graph_name'] ?? '';
        $c->executionId = $data['execution_id'] ?? '';
        $c->state = State::fromArray($data['state'] ?? []);
        $c->currentNode = $data['current_node'] ?? '';
        $c->metadata = $data['metadata'] ?? [];
        $c->createdAt = new \DateTimeImmutable($data['created_at'] ?? 'now');

        return $c;
    }

    public function loadLatestForExecution(string $executionId): ?Checkpoint
    {
        $versions = $this->listForExecution($executionId);
        if ($versions === []) {
            return null;
        }

        $latest = $versions[array_key_last($versions)];

        return $this->load($latest['id']);
    }

    /**
     * @return array<int, array{id: string, node: string, created_at: string}>
     */
    public function listForExecution(string $executionId): array
    {
        $ids = Redis::zrange($this->executionIndexKey($executionId), 0, -1);
        if ($ids === [] || $ids === false) {
            return [];
        }

        $rows = [];
        foreach ($ids as $id) {
            $checkpoint = $this->load((string) $id);
            if ($checkpoint === null) {
                continue;
            }

            $rows[] = [
                'id' => $checkpoint->id,
                'node' => $checkpoint->currentNode,
                'created_at' => $checkpoint->createdAt->format('c'),
                'sequence' => (int) ($checkpoint->metadata['checkpoint_sequence'] ?? 0),
            ];
        }

        return CheckpointVersionOrdering::sortAscending($rows);
    }

    /**
     * @return array<int, string>
     */
    public function list(string $graphName, int $limit = 50, int $offset = 0): array
    {
        return []; // Redis listing not implemented for brevity
    }

    public function delete(string $id): bool
    {
        $checkpoint = $this->load($id);
        if ($checkpoint !== null) {
            Redis::zrem($this->executionIndexKey($checkpoint->executionId), $id);
        }

        return Redis::del($this->prefix.$id) > 0;
    }

    public function cleanup(int $olderThanDays = 30): int
    {
        return 0;
    }

    private function executionIndexKey(string $executionId): string
    {
        return $this->prefix.'exec:'.$executionId;
    }
}
