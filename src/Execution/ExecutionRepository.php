<?php

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Support\Facades\DB;

class ExecutionRepository
{
    protected string $table = 'agenticgraph_executions';

    public function queued(string $id, string $graphName, array $initialState = []): void
    {
        DB::table($this->table)->updateOrInsert(
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
    }

    public function started(string $id, string $graphName, array $initialState = []): void
    {
        DB::table($this->table)->updateOrInsert(
            ['id' => $id],
            [
                'graph_name' => $graphName,
                'status' => 'running',
                'initial_state' => json_encode($initialState),
                'started_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function completed(string $id, array $finalState, float $duration): void
    {
        DB::table($this->table)->where('id', $id)->update([
            'status' => 'completed',
            'final_state' => json_encode($finalState),
            'duration' => $duration,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function failed(string $id, string $error): void
    {
        DB::table($this->table)->where('id', $id)->update([
            'status' => 'failed',
            'error' => $error,
            'updated_at' => now(),
        ]);
    }

    /**
     * Clean up old completed/failed executions
     */
    public function cleanup(int $olderThanDays = 30, ?string $status = null): int
    {
        $threshold = now()->subDays($olderThanDays);

        $query = DB::table($this->table)
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
     */
    public function getStats(string $graphName, int $days = 7): array
    {
        $since = now()->subDays($days);

        return DB::table($this->table)
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
     */
    public function findStuckExecutions(int $olderThanMinutes = 60): array
    {
        $threshold = now()->subMinutes($olderThanMinutes);

        return DB::table($this->table)
            ->where('status', 'running')
            ->where('started_at', '<', $threshold)
            ->get()
            ->toArray();
    }
}
