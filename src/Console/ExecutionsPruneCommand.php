<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\InterruptRecord;

/**
 * The only way to delete runs: completed and failed executions older than the retention limit, with everything stored
 * for them. A run's age is completed_at, else updated_at (a failed run has no completed_at). Off until
 * execution.retention_days is set or --days is given.
 *
 * Each run is deleted under its row lock, and only if it still qualifies (recovery can reopen a failed run): stream
 * events, interrupts, batch results, checkpoints (through StorageInterface, so Redis and file storage too), and the
 * row last. Stream events on their own connection and checkpoints outside the database are not in the transaction;
 * the row stays until they are gone, so a later pass finishes a run that failed half way.
 *
 * Redis stream events are left to their TTL. Data of a run whose row is already gone is not found.
 *
 * ponytail: runs that always fail to delete stay the oldest and use up --limit; fix their storage or raise the limit.
 */
class ExecutionsPruneCommand extends Command
{
    protected $signature = 'agenticgraph:executions:prune
        {--days= : Delete ended runs older than this many days (default: agenticgraph.execution.retention_days)}
        {--limit=500 : Most runs to delete in one pass}';

    protected $description = 'Delete completed and failed executions older than the retention limit, with all their data';

    public function handle(ExecutionRepository $repo, StorageInterface $storage): int
    {
        $option = $this->option('days');
        $configured = $option ?? config('agenticgraph.execution.retention_days');
        if ($option === null && in_array($configured, [null, '', 0, '0'], true)) {
            $this->info('Retention is off: set agenticgraph.execution.retention_days (AGENTICGRAPH_EXECUTION_RETENTION_DAYS) or pass --days.');

            return self::SUCCESS;
        }
        $days = filter_var($configured, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($days === false) {
            $this->error('The retention days must be a whole number of 1 or more. Nothing was deleted.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $ended = fn (): Builder => DB::table('agenticgraph_executions')
            ->whereIn('status', ['completed', 'failed'])
            ->whereRaw('COALESCE(completed_at, updated_at) < ?', [$cutoff]);
        $due = $ended()
            ->orderByRaw('COALESCE(completed_at, updated_at)')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');

        // The app may not have the table: it is only for the database stream store, now or earlier.
        $connection = config('agenticgraph.streaming.store.database.connection');
        $table = (string) config('agenticgraph.streaming.store.database.table', 'stream_events');
        $hasEvents = Schema::connection($connection)->hasTable($table);

        $pruned = $failed = 0;
        foreach ($due as $id) {
            $id = (string) $id;
            try {
                $pruned += DB::transaction(function () use ($id, $ended, $repo, $storage, $connection, $table, $hasEvents): int {
                    if ($ended()->where('id', $id)->lockForUpdate()->first() === null) {
                        return 0;
                    }
                    if ($hasEvents) {
                        DB::connection($connection)->table($table)->where('execution_id', $id)->delete();
                    }
                    InterruptRecord::query()->where('execution_id', $id)->delete();
                    $repo->deleteBatchResults($id);
                    foreach ($storage->listForExecution($id) as $checkpoint) {
                        $storage->delete($checkpoint['id']);
                    }

                    return DB::table('agenticgraph_executions')->where('id', $id)->delete();
                });
            } catch (\Throwable $e) {
                $failed++;
                Log::error('AgenticGraph: execution prune failed, the run stays for the next pass', [
                    'execution_id' => $id,
                    'error_type' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Pruned {$pruned} execution(s).");
        if ($failed > 0) {
            $this->error("{$failed} execution(s) could not be pruned and stay for the next pass; see the log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
