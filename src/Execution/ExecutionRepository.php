<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;

class ExecutionRepository
{
    private DatabaseManager $db;

    private LoggerInterface $logger;

    protected string $table = 'agenticgraph_executions';

    /**
     * Valid state transitions for execution lifecycle
     */
    protected array $validTransitions = [
        'queued' => ['running', 'failed'],
        'running' => ['completed', 'failed'],
        'completed' => [],
        'failed' => [],
    ];

    public function __construct(?DatabaseManager $db = null, ?LoggerInterface $logger = null)
    {
        $this->db = $db ?? app(DatabaseManager::class);
        $this->logger = $logger ?? app(LoggerInterface::class);
    }

    public function queued(string $id, string $graphName, array $initialState = []): void
    {
        $this->db->table($this->table)->updateOrInsert(
            ['id' => $id],
            [
                'graph_name' => $graphName,
                'status' => 'queued',
                'initial_state' => json_encode($initialState),
                'started_at' => null,
                'completed_at' => null,
                'duration' => null,
                'error' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        if ((bool) config('agenticgraph.logging.enabled', true)) {
            $this->logger->debug('Execution queued', [
                'execution_id' => $id,
                'graph_name' => $graphName,
            ]);
        }
    }

    public function started(string $id, string $graphName, array $initialState = []): void
    {
        // Verify the record exists and get current status
        $current = $this->db->table($this->table)->where('id', $id)->first();

        if (! $current) {
            // If record doesn't exist, create it first (shouldn't happen in normal flow)
            if ((bool) config('agenticgraph.logging.enabled', true)) {
                $this->logger->warning('Execution record not found when starting, creating new record', [
                    'execution_id' => $id,
                    'graph_name' => $graphName,
                ]);
            }
            $this->queued($id, $graphName, $initialState);
        } else {
            // Validate state transition
            if (! $this->canTransitionTo($id, 'running')) {
                if ((bool) config('agenticgraph.logging.enabled', true)) {
                    $this->logger->warning('Invalid state transition attempted', [
                        'execution_id' => $id,
                        'current_status' => $current->status,
                        'attempted_status' => 'running',
                    ]);
                }
            }
        }

        // Update to running status
        $this->db->table($this->table)->where('id', $id)->update([
            'status' => 'running',
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        if ((bool) config('agenticgraph.logging.enabled', true)) {
            $this->logger->debug('Execution started', [
                'execution_id' => $id,
                'graph_name' => $graphName,
                'previous_status' => $current ? $current->status : 'new',
            ]);
        }
    }

    public function completed(string $id, array $finalState, float $duration): void
    {
        // Verify state transition
        if (! $this->canTransitionTo($id, 'completed')) {
            $current = $this->db->table($this->table)->where('id', $id)->value('status');
            if ((bool) config('agenticgraph.logging.enabled', true)) {
                $this->logger->warning('Invalid state transition to completed', [
                    'execution_id' => $id,
                    'current_status' => $current,
                ]);
            }
        }

        $this->db->table($this->table)->where('id', $id)->update([
            'status' => 'completed',
            'final_state' => json_encode($finalState),
            'duration' => $duration,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        if ((bool) config('agenticgraph.logging.enabled', true)) {
            $this->logger->debug('Execution completed', [
                'execution_id' => $id,
                'duration' => $duration,
            ]);
        }
    }

    public function failed(string $id, string $error): void
    {
        // Verify state transition
        if (! $this->canTransitionTo($id, 'failed')) {
            $current = $this->db->table($this->table)->where('id', $id)->value('status');
            if ((bool) config('agenticgraph.logging.enabled', true)) {
                $this->logger->warning('Invalid state transition to failed', [
                    'execution_id' => $id,
                    'current_status' => $current,
                ]);
            }
        }

        $this->db->table($this->table)->where('id', $id)->update([
            'status' => 'failed',
            'error' => $error,
            'updated_at' => now(),
        ]);

        if ((bool) config('agenticgraph.logging.enabled', true)) {
            $this->logger->error('Execution failed', [
                'execution_id' => $id,
                'error' => $error,
            ]);
        }
    }

    /**
     * Check if a state transition is valid
     */
    public function canTransitionTo(string $id, string $newStatus): bool
    {
        $current = $this->db->table($this->table)->where('id', $id)->value('status');

        if (! $current) {
            // New execution can transition to any valid status
            return isset($this->validTransitions[$newStatus]);
        }

        $allowedTransitions = $this->validTransitions[$current] ?? [];

        return in_array($newStatus, $allowedTransitions);
    }

    /**
     * Get current status of an execution
     */
    public function getStatus(string $id): ?string
    {
        return $this->db->table($this->table)->where('id', $id)->value('status');
    }

    /**
     * Get full execution record
     */
    public function get(string $id): ?object
    {
        return $this->db->table($this->table)->where('id', $id)->first();
    }

    /**
     * Get execution history (all state transitions)
     *
     * @return array{}|array{
     *     id: string,
     *     graph_name: string,
     *     status: string,
     *     created_at: mixed,
     *     started_at: mixed,
     *     completed_at: mixed,
     *     duration: float|int|null,
     *     error: string|null
     * }
     */
    public function getHistory(string $id): array
    {
        $execution = $this->get($id);
        if (! $execution) {
            return [];
        }

        return [
            'id' => $execution->id,
            'graph_name' => $execution->graph_name,
            'status' => $execution->status,
            'created_at' => $execution->created_at,
            'started_at' => $execution->started_at,
            'completed_at' => $execution->completed_at,
            'duration' => $execution->duration,
            'error' => $execution->error,
        ];
    }

    /**
     * Clean up old completed/failed executions
     */
    public function cleanup(int $olderThanDays = 30, ?string $status = null): int
    {
        $threshold = now()->subDays($olderThanDays);

        $query = $this->db->table($this->table)
            ->where('completed_at', '<', $threshold);

        if ($status) {
            $query->where('status', $status);
        } else {
            // Only cleanup completed and failed, keep running/queued
            $query->whereIn('status', ['completed', 'failed']);
        }

        return $query->delete();
    }

    /**
     * Get execution statistics
     *
     * @return array<string, object>
     */
    public function getStats(string $graphName, int $days = 7): array
    {
        $since = now()->subDays($days);

        return $this->db->table($this->table)
            ->where('graph_name', $graphName)
            ->where('created_at', '>=', $since)
            ->selectRaw('
                status,
                COUNT(*) as count,
                AVG(duration) as avg_duration,
                MAX(duration) as max_duration,
                MIN(duration) as min_duration
            ')
            ->groupBy('status')
            ->get()
            ->keyBy('status')
            ->toArray();
    }

    /**
     * Find stuck executions (running for too long)
     *
     * @return array<int, object>
     */
    public function findStuckExecutions(int $olderThanMinutes = 60): array
    {
        $threshold = now()->subMinutes($olderThanMinutes);

        return $this->db->table($this->table)
            ->where('status', 'running')
            ->where('started_at', '<', $threshold)
            ->get()
            ->toArray();
    }
}
