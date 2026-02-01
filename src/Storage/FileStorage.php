<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Storage;

use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Execution\Checkpoint;

class FileStorage implements StorageInterface
{
    protected string $dir;

    protected string $indexFile;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? storage_path('app/agenticgraph');
        $this->indexFile = $this->dir.'/.index.json';

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

        // Remove JSON_PRETTY_PRINT for production (30-50% smaller files)
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
        ]), LOCK_EX);

        // Update index for fast listing
        $this->updateIndex($checkpoint);

        return $checkpoint->id;
    }

    public function load(string $id): ?Checkpoint
    {
        $path = $this->path($id);
        if (! file_exists($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true) ?? [];

        return $this->hydrateCheckpoint($data);
    }

    /**
     * @return array<int, string>
     */
    public function list(string $graphName, int $limit = 50, int $offset = 0): array
    {
        $index = $this->loadIndex();

        // Filter by graph name
        $filtered = array_filter($index, fn ($item) => $item['graph_name'] === $graphName);

        // Sort by created_at descending
        usort($filtered, fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        // Apply limit and offset
        $slice = array_slice($filtered, $offset, $limit);

        // Return list of checkpoint IDs (for backward compatibility)
        return array_map(fn ($item) => $item['id'], $slice);
    }

    public function delete(string $id): bool
    {
        $path = $this->path($id);
        if (file_exists($path)) {
            $deleted = unlink($path);
            if ($deleted) {
                $this->removeFromIndex($id);
            }

            return $deleted;
        }

        return false;
    }

    public function cleanup(int $olderThanDays = 30): int
    {
        $count = 0;
        $threshold = time() - ($olderThanDays * 86400);

        // Use index for efficient cleanup instead of scanning all files
        $index = $this->loadIndex();

        foreach ($index as $item) {
            $createdAt = strtotime($item['created_at']);
            if ($createdAt !== false && $createdAt < $threshold) {
                if ($this->delete($item['id'])) {
                    $count++;
                }
            }
        }

        return $count;
    }

    protected function path(string $id): string
    {
        // Sanitize ID to prevent directory traversal
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);

        return $this->dir.'/'.$safeId.'.json';
    }

    /**
     * @return array<int, array{id: string, graph_name: string, execution_id: string, current_node: string, created_at: string}>
     */
    private function loadIndex(): array
    {
        if (! file_exists($this->indexFile)) {
            return [];
        }

        $json = file_get_contents($this->indexFile);

        return json_decode($json, true) ?: [];
    }

    private function updateIndex(Checkpoint $checkpoint): void
    {
        $index = $this->loadIndex();

        // Remove existing entry if present
        $index = array_filter($index, fn ($item) => $item['id'] !== $checkpoint->id);

        // Add new entry
        $index[] = [
            'id' => $checkpoint->id,
            'graph_name' => $checkpoint->graphName,
            'execution_id' => $checkpoint->executionId,
            'current_node' => $checkpoint->currentNode,
            'created_at' => $checkpoint->createdAt->format('c'),
        ];

        // Write atomically
        $this->writeIndex($index);
    }

    private function removeFromIndex(string $id): void
    {
        $index = $this->loadIndex();
        $index = array_filter($index, fn ($item) => $item['id'] !== $id);
        $this->writeIndex($index);
    }

    /**
     * @param  array<int, array{id: string, graph_name: string, execution_id: string, current_node: string, created_at: string}>  $index
     */
    private function writeIndex(array $index): void
    {
        $tempFile = $this->indexFile.'.tmp';
        file_put_contents($tempFile, json_encode(array_values($index)), LOCK_EX);
        rename($tempFile, $this->indexFile);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hydrateCheckpoint(array $data): Checkpoint
    {
        $c = new Checkpoint;
        $c->id = $data['id'] ?? '';
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
}
