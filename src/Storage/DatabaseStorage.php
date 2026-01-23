<?php

namespace Shahzad\AgenticGraph\Storage;

use Illuminate\Support\Facades\DB;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Execution\Checkpoint;

class DatabaseStorage implements StorageInterface
{
    protected string $table = 'agenticgraph_checkpoints';

    public function save(Checkpoint $checkpoint): string
    {
        $payload = [
            'id' => $checkpoint->id,
            'graph_name' => $checkpoint->graphName,
            'execution_id' => $checkpoint->executionId,
            'state' => json_encode($checkpoint->state->toArray()),
            'current_node' => $checkpoint->currentNode,
            'completed_nodes' => json_encode($checkpoint->completedNodes),
            'pending_nodes' => json_encode($checkpoint->pendingNodes),
            'metadata' => json_encode($checkpoint->metadata),
            'created_at' => $checkpoint->createdAt->format('Y-m-d H:i:s'),
            'parent_checkpoint_id' => $checkpoint->parentCheckpointId,
        ];
        DB::table($this->table)->updateOrInsert(['id' => $checkpoint->id], $payload);

        return $checkpoint->id;
    }

    public function load(string $id): ?Checkpoint
    {
        $row = DB::table($this->table)->where('id', $id)->first();
        if (! $row) {
            return null;
        }
        $c = new Checkpoint;
        $c->id = $row->id;
        $c->graphName = $row->graph_name;
        $c->executionId = $row->execution_id;
        $c->state = \Shahzad\AgenticGraph\State\State::fromArray(json_decode($row->state, true) ?? []);
        $c->currentNode = $row->current_node;
        $c->completedNodes = json_decode($row->completed_nodes, true) ?? [];
        $c->pendingNodes = json_decode($row->pending_nodes, true) ?? [];
        $c->metadata = json_decode($row->metadata, true) ?? [];
        $c->createdAt = new \DateTimeImmutable($row->created_at);
        $c->parentCheckpointId = $row->parent_checkpoint_id;

        return $c;
    }

    public function list(string $graphName, int $limit = 50, int $offset = 0): array
    {
        return DB::table($this->table)
            ->where('graph_name', $graphName)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(function ($row) {
                return [
                    'id' => $row->id,
                    'execution_id' => $row->execution_id,
                    'current_node' => $row->current_node,
                    'created_at' => $row->created_at,
                ];
            })
            ->all();
    }

    public function delete(string $id): bool
    {
        return DB::table($this->table)->where('id', $id)->delete() > 0;
    }

    public function cleanup(int $olderThanDays = 30): int
    {
        $threshold = now()->subDays($olderThanDays)->format('Y-m-d H:i:s');

        return DB::table($this->table)->where('created_at', '<', $threshold)->delete();
    }
}
