<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Events\InterruptExpired;
use Shahzad\AgenticGraph\Exceptions\InterruptExpiredException;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Jobs\EndsFailedExecution;

/**
 * Moves pending interrupts past expires_at to expired. Each one's execution becomes failed and its stream ends with
 * graph_failed (reason interrupt_expired), then the terminal, through the same helpers as a failed resume job.
 * Each interrupt and its run are ended in their own transaction: one that fails stays pending for the next run. Only
 * after the commit: the stream frames and GraphExecutionFailed, for the call that changed the run row, then
 * InterruptExpired, for the call that claimed the interrupt. A stream write or a listener that throws there is
 * reported; both rows stay ended and the command goes on.
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
                $announce = DB::transaction(fn (): ?\Closure => $this->expire($interrupt));
            } catch (\Throwable $e) {
                Log::error('AgenticGraph: interrupt expiry failed, left pending', [
                    'interrupt_id' => $interrupt->interruptId(),
                    'execution_id' => $interrupt->executionId(),
                    'error_type' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            if ($announce !== null) {
                $expired++;
                $announce();
            }
        }

        $this->info("Expired {$expired} interrupt(s).");

        return self::SUCCESS;
    }

    /**
     * @return (\Closure(): void)|null what to do after the commit; null when this call did not claim the interrupt
     */
    private function expire(InterruptRecord $interrupt): ?\Closure
    {
        // Conditional: a resume that marked it resolving first keeps it.
        $claimed = InterruptRecord::query()
            ->whereKey($interrupt->getKey())
            ->where('status', 'pending')
            ->update(['status' => 'expired', 'updated_at' => now()]);
        if ($claimed !== 1) {
            return null;
        }

        $interrupt->refresh();

        return $this->endExpiredExecution($interrupt);
    }

    /**
     * @return \Closure(): void
     */
    private function endExpiredExecution(InterruptRecord $interrupt): \Closure
    {
        $executionId = (string) $interrupt->executionId();
        if (! app(ExecutionRepository::class)->exists($executionId)) {
            return static function (): void {}; // A deleted run: the interrupt stays expired, with no stream event and no InterruptExpired.
        }
        $record = app(ExecutionStore::class)->get($executionId);
        $metadata = $interrupt->metadata();
        $metadata = is_array($metadata) ? $metadata : [];
        $delivery = $metadata['stream_delivery'] ?? null;
        $streamId = ($record !== null ? $record->streamId : null) ?? ($metadata['stream_id'] ?? null);
        $lastSeq = $metadata['stream_last_seq'] ?? null;
        $finalState = $record?->finalState;
        $graphName = $record !== null ? $record->graphName : (string) $interrupt->graphName();

        $checkpoint = app(StorageInterface::class)->load((string) $interrupt->checkpointId());
        $rootSpanId = $checkpoint?->metadata['trace_context']['root_span_id'] ?? null;

        $error = new InterruptExpiredException($interrupt);
        $changed = $this->failExpiredExecution($error, $executionId, InterruptExpiredException::REASON);

        $expiresAt = $interrupt->getAttribute('expires_at');
        $expired = new InterruptExpired(
            interruptId: (int) $interrupt->interruptId(),
            executionId: $executionId,
            graphName: (string) $interrupt->graphName(),
            nodeName: (string) $interrupt->nodeName(),
            checkpointId: (string) $interrupt->checkpointId(),
            expiresAt: $expiresAt instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($expiresAt) : null,
            occurredAt: new \DateTimeImmutable,
        );

        return function () use ($changed, $error, $expired, $executionId, $graphName, $finalState, $streamId, $delivery, $record, $rootSpanId, $lastSeq): void {
            if ($changed) {
                // The trace continues the interrupted one, as a resume does: its root span id.
                $this->announceExpiredExecution(
                    $error,
                    $executionId,
                    $graphName,
                    is_array($finalState) ? $finalState : [],
                    is_string($streamId) ? $streamId : null,
                    is_string($delivery) ? StreamingMode::tryFrom($delivery) : null,
                    $record?->executionOptions,
                    is_string($rootSpanId) ? $rootSpanId : null,
                    is_int($lastSeq) ? $lastSeq : null,
                    InterruptExpiredException::REASON,
                );
            }

            rescue(fn () => event($expired));
        };
    }
}
