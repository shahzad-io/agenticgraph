<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;

class ExecutionRepository implements ExecutionStore
{
    private DatabaseManager $db;

    private LoggerInterface $logger;

    protected string $table = 'agenticgraph_executions';

    /**
     * Valid state transitions for execution lifecycle
     *
     * @var array<string, array<int, string>>
     */
    protected array $validTransitions = [
        'queued' => ['running', 'failed', 'interrupted'],
        'running' => ['completed', 'failed', 'interrupted'],
        'interrupted' => ['running', 'failed', 'completed'],
        'completed' => [],
        'failed' => [],
    ];

    public function __construct(?DatabaseManager $db = null, ?LoggerInterface $logger = null)
    {
        $this->db = $db ?? app(DatabaseManager::class);
        $this->logger = $logger ?? app(LoggerInterface::class);
    }

    /**
     * @param  array<string, mixed>  $initialState
     */
    public function queued(string $id, string $graphName, array $initialState = [], ?string $streamId = null): void
    {
        $this->db->transaction(function () use ($id, $graphName, $initialState, $streamId) {
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
                    'stream_id' => $streamId,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        });

        if ((bool) config('agenticgraph.logging.enabled', true)) {
            $this->logger->debug('Execution queued', [
                'execution_id' => $id,
                'graph_name' => $graphName,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $initialState
     */
    public function started(string $id, string $graphName, array $initialState = [], ?string $streamId = null): void
    {
        $this->db->transaction(function () use ($id, $graphName, $initialState, $streamId) {
            // Verify the record exists and get current status with lock
            $current = $this->db->table($this->table)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $current) {
                // If record doesn't exist, create it first (shouldn't happen in normal flow)
                if ((bool) config('agenticgraph.logging.enabled', true)) {
                    $this->logger->warning('Execution record not found when starting, creating new record', [
                        'execution_id' => $id,
                        'graph_name' => $graphName,
                    ]);
                }
                $this->queued($id, $graphName, $initialState);
                $current = (object) ['status' => 'queued'];
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
                'stream_id' => $streamId,
                'updated_at' => now(),
            ]);

            if ((bool) config('agenticgraph.logging.enabled', true)) {
                $this->logger->debug('Execution started', [
                    'execution_id' => $id,
                    'graph_name' => $graphName,
                    'previous_status' => $current->status ?? 'new',
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $finalState
     */
    public function completed(string $id, array $finalState, float $duration): void
    {
        $this->db->transaction(function () use ($id, $finalState, $duration) {
            // Verify state transition with lock
            if (! $this->canTransitionTo($id, 'completed')) {
                $current = $this->db->table($this->table)
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->value('status');
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
        });

        if ((bool) config('agenticgraph.logging.enabled', true)) {
            $this->logger->debug('Execution completed', [
                'execution_id' => $id,
                'duration' => $duration,
            ]);
        }
    }

    public function failed(string $id, string $error): void
    {
        $this->db->transaction(function () use ($id, $error) {
            // Verify state transition with lock
            if (! $this->canTransitionTo($id, 'failed')) {
                $current = $this->db->table($this->table)
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->value('status');
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
        });

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

    public function get(string $executionId): ?ExecutionRecord
    {
        $row = $this->db->table($this->table)->where('id', $executionId)->first();
        if (! $row) {
            return null;
        }

        return new ExecutionRecord(
            executionId: $row->id,
            graphName: $row->graph_name,
            status: ExecutionStatus::from($row->status),
            initialState: json_decode($row->initial_state ?? '[]', true),
            finalState: $row->final_state ? json_decode($row->final_state, true) : null,
            streamId: $row->stream_id ?? null,
            interruptedAt: $row->interrupted_at ? new \DateTimeImmutable($row->interrupted_at) : null,
            completedAt: $row->completed_at ? new \DateTimeImmutable($row->completed_at) : null,
            error: $row->error ?? null
        );
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
        // Single query to get execution record - no N+1 issue here as we only fetch one record
        $execution = $this->db->table($this->table)
            ->where('id', $id)
            ->select([
                'id',
                'graph_name',
                'status',
                'created_at',
                'started_at',
                'completed_at',
                'duration',
                'error',
            ])
            ->first();

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

    public function store(ExecutionRecord $record): void
    {
        $this->db->table($this->table)->updateOrInsert(
            ['id' => $record->executionId],
            [
                'graph_name' => $record->graphName,
                'status' => $record->status->value,
                'initial_state' => $record->initialState ? json_encode($record->initialState) : null,
                'final_state' => $record->finalState ? json_encode($record->finalState) : null,
                'stream_id' => $record->streamId,
                'interrupted_at' => $record->interruptedAt?->format('Y-m-d H:i:s'),
                'completed_at' => $record->completedAt?->format('Y-m-d H:i:s'),
                'error' => $record->error,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function updateStatus(string $executionId, ExecutionStatus $status, mixed $finalState = null): void
    {
        $payload = [
            'status' => $status->value,
            'updated_at' => now(),
        ];
        if ($finalState !== null) {
            $payload['final_state'] = json_encode($finalState);
        }
        if ($status === ExecutionStatus::COMPLETED) {
            $payload['completed_at'] = now();
        }
        if ($status === ExecutionStatus::INTERRUPTED) {
            $payload['interrupted_at'] = now();
        }
        $this->db->table($this->table)->where('id', $executionId)->update($payload);
    }

    public function interrupted(string $id): void
    {
        $this->updateStatus($id, ExecutionStatus::INTERRUPTED);
    }

    /**
     * Get execution history for multiple IDs efficiently
     *
     * @param  array<int, string>  $ids
     * @return array<string, array<string, mixed>>
     */
    public function getHistoryBatch(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $executions = $this->db->table($this->table)
            ->whereIn('id', $ids)
            ->select([
                'id',
                'graph_name',
                'status',
                'created_at',
                'started_at',
                'completed_at',
                'duration',
                'error',
            ])
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($executions as $id => $execution) {
            $result[$id] = [
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

        return $result;
    }

    /**
     * Clean up old completed/failed executions
     */
    public function cleanup(int $olderThanDays = 30, ?string $status = null): int
    {
        $threshold = now()->subDays($olderThanDays);

        return $this->db->transaction(function () use ($threshold, $status) {
            $query = $this->db->table($this->table)
                ->where('completed_at', '<', $threshold);

            if ($status) {
                $query->where('status', $status);
            } else {
                // Only cleanup completed and failed, keep running/queued
                $query->whereIn('status', ['completed', 'failed']);
            }

            return $query->delete();
        });
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

        // Single query with proper index usage - no N+1 issue
        return $this->db->table($this->table)
            ->where('status', 'running')
            ->where('started_at', '<', $threshold)
            ->select([
                'id',
                'graph_name',
                'status',
                'started_at',
                'created_at',
            ])
            ->get()
            ->toArray();
    }

    /**
     * Find and mark stuck executions as failed
     *
     * @return array<int, string> Array of execution IDs that were marked as failed
     */
    public function markStuckExecutionsAsFailed(int $olderThanMinutes = 60): array
    {
        $threshold = now()->subMinutes($olderThanMinutes);

        return $this->db->transaction(function () use ($threshold, $olderThanMinutes) {
            // Find stuck executions with lock
            $stuckIds = $this->db->table($this->table)
                ->where('status', 'running')
                ->where('started_at', '<', $threshold)
                ->lockForUpdate()
                ->pluck('id')
                ->toArray();

            if (empty($stuckIds)) {
                return [];
            }

            // Mark as failed
            $this->db->table($this->table)
                ->whereIn('id', $stuckIds)
                ->update([
                    'status' => 'failed',
                    'error' => "Execution marked as failed after being stuck for more than {$olderThanMinutes} minutes",
                    'updated_at' => now(),
                ]);

            foreach ($stuckIds as $id) {
                if ((bool) config('agenticgraph.logging.enabled', true)) {
                    $this->logger->warning('Execution marked as failed due to being stuck', [
                        'execution_id' => $id,
                        'threshold_minutes' => $olderThanMinutes,
                    ]);
                }
            }

            return $stuckIds;
        });
    }
}
