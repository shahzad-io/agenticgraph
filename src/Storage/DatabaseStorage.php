<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Storage;

use Illuminate\Database\DatabaseManager;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Support\CheckpointVersionOrdering;

class DatabaseStorage implements StorageInterface
{
    private DatabaseManager $db;

    protected string $table = 'agenticgraph_checkpoints';

    public function __construct(?DatabaseManager $db = null)
    {
        $this->db = $db ?? app(DatabaseManager::class);
    }

    public function save(Checkpoint $checkpoint): string
    {
        $statePayload = State::toStoragePayload($checkpoint->state);
        $payload = [
            'id' => $checkpoint->id,
            'graph_name' => $checkpoint->graphName,
            'execution_id' => $checkpoint->executionId,
            'state' => json_encode($statePayload),
            'current_node' => $checkpoint->currentNode,
            'metadata' => json_encode($checkpoint->metadata),
            'created_at' => $checkpoint->createdAt->format('Y-m-d H:i:s.u'),
        ];
        $this->db->table($this->table)->updateOrInsert(['id' => $checkpoint->id], $payload);

        return $checkpoint->id;
    }

    public function load(string $id): ?Checkpoint
    {
        $row = $this->db->table($this->table)->where('id', $id)->first();
        if (! $row) {
            return null;
        }

        return $this->hydrateFromRow($row);
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
        $rows = $this->db->table($this->table)
            ->where('execution_id', $executionId)
            ->get()
            ->map(function ($row): array {
                $metadata = json_decode($row->metadata, true) ?? [];

                return [
                    'id' => $row->id,
                    'node' => $row->current_node,
                    'created_at' => (string) $row->created_at,
                    'sequence' => (int) ($metadata['checkpoint_sequence'] ?? 0),
                ];
            })
            ->all();

        return CheckpointVersionOrdering::sortAscending($rows);
    }

    /**
     * @return array<int, array{id: string, execution_id: string, current_node: string, created_at: mixed}>
     */
    public function list(string $graphName, int $limit = 50, int $offset = 0): array
    {
        return $this->db->table($this->table)
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
        return $this->db->table($this->table)->where('id', $id)->delete() > 0;
    }

    public function cleanup(int $olderThanDays = 30): int
    {
        $threshold = now()->subDays($olderThanDays)->format('Y-m-d H:i:s');

        return $this->db->table($this->table)->where('created_at', '<', $threshold)->delete();
    }

    private function hydrateFromRow(object $row): Checkpoint
    {
        $c = new Checkpoint;
        $c->id = $row->id;
        $c->graphName = $row->graph_name;
        $c->executionId = $row->execution_id;
        $c->state = State::fromArray(json_decode($row->state, true) ?? []);
        $c->currentNode = $row->current_node;
        $c->metadata = json_decode($row->metadata, true) ?? [];
        $c->createdAt = new \DateTimeImmutable($row->created_at);

        return $c;
    }
}
