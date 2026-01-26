<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Storage;

use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Execution\Checkpoint;

class FileStorage implements StorageInterface
{
    protected string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? storage_path('app/agenticgraph');

        if (! is_dir($this->dir)) {
            if (! mkdir($this->dir, 0775, true) && ! is_dir($this->dir)) {
                throw new \RuntimeException(
                    "Failed to create checkpoint directory: {$this->dir}. ".
                    'Check directory permissions.'
                );
            }
        }

        if (! is_writable($this->dir)) {
            throw new \RuntimeException(
                "Checkpoint directory is not writable: {$this->dir}. ".
                'Check directory permissions.'
            );
        }
    }

    public function save(Checkpoint $checkpoint): string
    {
        $path = $this->path($checkpoint->id);
        file_put_contents($path, json_encode([
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
        ], JSON_PRETTY_PRINT));

        return $checkpoint->id;
    }

    public function load(string $id): ?Checkpoint
    {
        $path = $this->path($id);
        if (! file_exists($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true) ?? [];
        $c = new Checkpoint;
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

    /**
     * @return array<int, string>
     */
    public function list(string $graphName, int $limit = 50): array
    {
        $files = glob($this->dir.'/*.json') ?: [];
        $filtered = [];

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (isset($data['graph_name']) && $data['graph_name'] === $graphName) {
                $filtered[] = basename($file, '.json');
            }

            if (count($filtered) >= $limit) {
                break;
            }
        }

        return $filtered;
    }

    public function delete(string $id): bool
    {
        $path = $this->path($id);
        if (file_exists($path)) {
            return unlink($path);
        }

        return false;
    }

    public function cleanup(int $olderThanDays = 30): int
    {
        $count = 0;
        $threshold = time() - ($olderThanDays * 86400);
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            if (filemtime($file) < $threshold) {
                if (! is_writable($file)) {
                    continue;
                }
                if (unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    protected function path(string $id): string
    {
        return $this->dir.'/'.$id.'.json';
    }
}
