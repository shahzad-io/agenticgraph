<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Events\InterruptExpired;
use Shahzad\AgenticGraph\Exceptions\InterruptExpiredException;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Jobs\EndsFailedExecution;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\BufferedTraceExporter;

/**
 * Moves pending interrupts past expires_at to expired. Each one's execution becomes failed and its stream ends with
 * graph_failed (reason interrupt_expired), then the terminal, through the same helpers as a failed resume job.
 * Each interrupt is expired in its own transaction: one that fails stays pending for the next run, and its
 * final trace segment is sent only after the commit.
 */
class InterruptExpireCommand extends Command
{
    use EndsFailedExecution;

    protected $signature = 'agenticgraph:interrupt:expire {--limit=500 : Most interrupts to expire in one run}';

    protected $description = 'Expire pending interrupts past their expires_at and end their executions';

    public function handle(): int
    {
        $due = InterruptRecord::query()
            ->pending()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $expired = 0;
        foreach ($due as $interrupt) {
            try {
                $expired += DB::transaction(fn (): int => $this->expire($interrupt));
            } catch (\Throwable $e) {
                Log::error('AgenticGraph: interrupt expiry failed, left pending', [
                    'interrupt_id' => $interrupt->interruptId(),
                    'execution_id' => $interrupt->executionId(),
                    'error_type' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Expired {$expired} interrupt(s).");

        return self::SUCCESS;
    }

    private function expire(InterruptRecord $interrupt): int
    {
        // Conditional: a resume that marked it resolving first keeps it.
        $claimed = InterruptRecord::query()
            ->whereKey($interrupt->getKey())
            ->where('status', 'pending')
            ->update(['status' => 'expired', 'updated_at' => now()]);
        if ($claimed !== 1) {
            return 0;
        }

        $interrupt->refresh();
        $this->endExpiredExecution($interrupt);

        return 1;
    }

    private function endExpiredExecution(InterruptRecord $interrupt): void
    {
        $executionId = (string) $interrupt->executionId();
        $record = app(ExecutionStore::class)->get($executionId);
        $metadata = $interrupt->metadata();
        $metadata = is_array($metadata) ? $metadata : [];
        $delivery = $metadata['stream_delivery'] ?? null;
        $streamId = ($record !== null ? $record->streamId : null) ?? ($metadata['stream_id'] ?? null);
        $lastSeq = $metadata['stream_last_seq'] ?? null;
        $finalState = $record?->finalState;
        $graphName = $record !== null ? $record->graphName : (string) $interrupt->graphName();

        // Continue the interrupted trace as a resume does: its root span id, in a segment that names the graph.
        $checkpoint = app(StorageInterface::class)->load((string) $interrupt->checkpointId());
        $rootSpanId = $checkpoint?->metadata['trace_context']['root_span_id'] ?? null;
        /** @var TracerExporter|null $exporter */
        $exporter = app()->bound(TracerExporter::class) ? app(TracerExporter::class) : null;
        if ($exporter?->isEnabled()) {
            $exporter->resumeTrace($executionId, $graphName, 'continued');
            if ($exporter instanceof BufferedTraceExporter) {
                $exporter->sendAfterCommit($executionId);
            }
        }

        $this->endFailedExecution(
            new InterruptExpiredException($interrupt),
            $executionId,
            $graphName,
            is_array($finalState) ? $finalState : [],
            is_string($streamId) ? $streamId : null,
            is_string($delivery) ? StreamingMode::tryFrom($delivery) : null,
            $record?->executionOptions,
            rootSpanId: is_string($rootSpanId) ? $rootSpanId : null,
            fallbackSeq: is_int($lastSeq) ? $lastSeq : null,
            reason: InterruptExpiredException::REASON,
        );

        $expiresAt = $interrupt->getAttribute('expires_at');
        event(new InterruptExpired(
            interruptId: (int) $interrupt->interruptId(),
            executionId: $executionId,
            graphName: (string) $interrupt->graphName(),
            nodeName: (string) $interrupt->nodeName(),
            checkpointId: (string) $interrupt->checkpointId(),
            expiresAt: $expiresAt instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($expiresAt) : null,
            occurredAt: new \DateTimeImmutable,
        ));
    }
}
