<?php

namespace Shahzad\AgenticGraph\Storage;

use Illuminate\Support\Facades\Redis;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Execution\Checkpoint;

class RedisStorage implements StorageInterface
{
    protected string $prefix = 'agenticgraph:checkpoint:';

    public function save(Checkpoint $checkpoint): string
    {
        $key = $this->prefix . $checkpoint->id;
        $payload = json_encode([
            'id' => $checkpoint->id,
            'graph_name' => $checkpoint->graphName,
            'execution_id' => $checkpoint->executionId,
            'state' => $checkpoint->state->toArray(),
            'current_node' => $checkpoint->currentNode,
            'completed_nodes' => $checkpoint->completedNodes,
            'pending_nodes' => $checkpoint->pendingNodes,
            'metadata' => $checkpoint->metadata,
            'created_at' => $checkpoint->createdAt->format('c'),
            'parent_checkpoint_id' => $checkpoint->parentCheckpointId,
        ]);
        Redis::set($key, $payload);
        $ttl = (int) config('agenticgraph.checkpoint.ttl', 7) * 86400;
        if ($ttl > 0) {
            Redis::expire($key, $ttl);
        }
        return $checkpoint->id;
    }

    public function load(string $id): ?Checkpoint
    {
        $payload = Redis::get($this->prefix . $id);
        if (!$payload) {
            return null;
        }
        $data = json_decode($payload, true) ?? [];
        $c = new Checkpoint();
        $c->id = $data['id'] ?? $id;
        $c->graphName = $data['graph_name'] ?? '';
        $c->executionId = $data['execution_id'] ?? '';
        $c->state = \Shahzad\AgenticGraph\State\State::fromArray($data['state'] ?? []);
        $c->currentNode = $data['current_node'] ?? '';
        $c->completedNodes = $data['completed_nodes'] ?? [];
        $c->pendingNodes = $data['pending_nodes'] ?? [];
        $c->metadata = $data['metadata'] ?? [];
        $c->createdAt = new \DateTimeImmutable($data['created_at'] ?? 'now');
        $c->parentCheckpointId = $data['parent_checkpoint_id'] ?? null;
        return $c;
    }

    public function list(string $graphName, int $limit = 50): array
    {
        return []; // Redis listing not implemented for brevity
    }

    public function delete(string $id): bool
    {
        return Redis::del($this->prefix . $id) > 0;
    }

    public function cleanup(int $olderThanDays = 30): int
    {
        return 0;
    }
}