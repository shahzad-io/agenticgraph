<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Exceptions\StaleExecutionException;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Jobs\EndsFailedExecution;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\BufferedTraceExporter;

/**
 * Fails async executions no worker is running, so the visitor's stream gets a terminal event:
 *  - queued longer than execution.queued_timeout (no worker picked the job up);
 *  - running with no progress for longer than max(execution.timeout, execution.resume_timeout) + execution.stale_grace
 *    (the worker died, for example killed at its --timeout), or execution.stale_running_after when the timeout is 0.
 * Progress is `updated_at`, which a running execution refreshes whenever a node completes. Interrupted executions have
 * their own expiry (agenticgraph:interrupt:expire) and sync executions have no worker, so neither is touched.
 *
 * Each execution is ended in its own transaction, as a failed job ends it (status failed, graph_failed, the failed
 * execution_terminal, GraphExecutionFailed with a reason, a final trace segment on the original root span); one that
 * fails stays as it was for the next run, and its trace segment is sent only after the commit.
 *
 * ponytail: a single queued branch job (ExecuteNodeJob) that outlives timeout + grace without completing a node
 * reads as dead; give those jobs a $timeout from execution.timeout if that matters.
 */
class ExecutionsExpireCommand extends Command
{
    use EndsFailedExecution;

    protected $signature = 'agenticgraph:executions:expire {--limit=500 : Most executions to expire in one run}';

    protected $description = 'Fail queued executions no worker picked up and running executions whose worker died';

    private int $queuedAfter = 300;

    private int $runningAfter = 900;

    public function handle(): int
    {
        $this->queuedAfter = max(1, (int) config('agenticgraph.execution.queued_timeout', 300));
        $this->runningAfter = $this->runningThreshold();

        $due = $this->staleQuery()
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');

        $expired = 0;
        foreach ($due as $executionId) {
            try {
                $expired += DB::transaction(fn (): int => $this->expire((string) $executionId));
            } catch (\Throwable $e) {
                Log::error('AgenticGraph: execution expiry failed, left as is', [
                    'execution_id' => (string) $executionId,
                    'error_type' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Expired {$expired} execution(s).");

        return self::SUCCESS;
    }

    /**
     * Seconds without progress before a running execution counts as dead.
     */
    private function runningThreshold(): int
    {
        $timeout = (int) config('agenticgraph.execution.timeout', 0);
        $resumeTimeout = (int) config('agenticgraph.execution.resume_timeout', 0);
        $grace = max(0, (int) config('agenticgraph.execution.stale_grace', 60));

        if ($timeout > 0) {
            return max($timeout, $resumeTimeout) + $grace;
        }

        // The package cannot see the worker's own --timeout, so it uses its own limit.
        return max(1, (int) config('agenticgraph.execution.stale_running_after', 900), $resumeTimeout > 0 ? $resumeTimeout + $grace : 0);
    }

    /**
     * The one definition of stale, used to find candidates and again under the row lock.
     */
    private function staleQuery(): Builder
    {
        $queuedCut = now()->subSeconds($this->queuedAfter);
        $runningCut = now()->subSeconds($this->runningAfter);

        return DB::table('agenticgraph_executions')->where(function (Builder $query) use ($queuedCut, $runningCut): void {
            $query->where(fn (Builder $queued) => $queued->where('status', 'queued')->where('created_at', '<=', $queuedCut))
                ->orWhere(fn (Builder $running) => $running->where('status', 'running')
                    ->where('started_at', '<=', $runningCut)
                    ->where('updated_at', '<=', $runningCut));
        });
    }

    private function expire(string $executionId): int
    {
        // Re-read under the lock: a run that started, made progress or ended since the candidate query keeps its row.
        $row = $this->staleQuery()->where('id', $executionId)->lockForUpdate()->first();
        if ($row === null) {
            return 0;
        }

        $options = ExecutionOptions::fromStored($row->execution_options ?? null);
        if ($options->mode !== ExecutionMode::ASYNC) {
            return 0;
        }

        $queued = $row->status === 'queued';
        $this->endStaleExecution(
            $executionId,
            $options,
            $queued ? StaleExecutionException::REASON_QUEUED : StaleExecutionException::REASON_RUNNING,
            $queued ? $this->queuedAfter : $this->runningAfter,
            $this->storedRootSpanId($row->execution_options ?? null),
        );

        return 1;
    }

    private function endStaleExecution(string $executionId, ExecutionOptions $options, string $reason, int $seconds, ?string $rootSpanId): void
    {
        $record = app(ExecutionStore::class)->get($executionId);
        if ($record === null) {
            return;
        }

        $state = $record->finalState ?? $record->initialState;
        $streaming = $options->streaming === StreamingMode::AUTO ? StreamingMode::SSE : $options->streaming;
        $rootSpanId ??= $this->interruptedRootSpanId($executionId);

        // One failed segment that continues the original trace: its root span id, the graph name, timestamps after
        // every earlier segment, sent after the commit (a rollback drops it).
        /** @var TracerExporter|null $exporter */
        $exporter = app()->bound(TracerExporter::class) ? app(TracerExporter::class) : null;
        if ($exporter?->isEnabled()) {
            $exporter->resumeTrace($executionId, $record->graphName, 'continued');
            if ($exporter instanceof BufferedTraceExporter) {
                $exporter->sendAfterCommit($executionId);
            }
        }

        $this->endFailedExecution(
            new StaleExecutionException($executionId, $reason, $seconds),
            $executionId,
            $record->graphName,
            is_array($state) ? $state : [],
            $record->streamId,
            $streaming,
            $options,
            rootSpanId: $rootSpanId,
            reason: $reason,
        );
    }

    /**
     * The trace's root span id stored when the execution was queued (inside execution_options).
     */
    private function storedRootSpanId(mixed $optionsJson): ?string
    {
        $data = is_string($optionsJson) ? json_decode($optionsJson, true) : null;
        $id = is_array($data) ? ($data[ExecutionRepository::ROOT_SPAN_KEY] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Fallback for a row queued before the root span id was stored: a resumed run's interrupt checkpoint has it.
     */
    private function interruptedRootSpanId(string $executionId): ?string
    {
        $interrupt = InterruptRecord::query()->forExecution($executionId)->latest('id')->first();
        if ($interrupt === null) {
            return null;
        }

        $checkpoint = app(StorageInterface::class)->load((string) $interrupt->checkpointId());
        $id = $checkpoint?->metadata['trace_context']['root_span_id'] ?? null;

        return is_string($id) ? $id : null;
    }
}
