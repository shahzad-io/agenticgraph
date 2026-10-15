<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Shahzad\AgenticGraph\Events\InterruptExpired;
use Shahzad\AgenticGraph\Exceptions\InterruptExpiredException;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Jobs\EndsFailedExecution;

/**
 * Moves pending interrupts past expires_at to expired. Each one's execution becomes failed and its stream ends with
 * graph_failed (reason interrupt_expired), then the terminal, through the same helpers as a failed resume job.
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
            // Conditional: a resume that marked it resolving first keeps it.
            $claimed = InterruptRecord::query()
                ->whereKey($interrupt->getKey())
                ->where('status', 'pending')
                ->update(['status' => 'expired', 'updated_at' => now()]);
            if ($claimed !== 1) {
                continue;
            }

            $interrupt->refresh();
            $this->endExpiredExecution($interrupt);
            $expired++;
        }

        $this->info("Expired {$expired} interrupt(s).");

        return self::SUCCESS;
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

        $this->endFailedExecution(
            new InterruptExpiredException($interrupt),
            $executionId,
            $record !== null ? $record->graphName : (string) $interrupt->graphName(),
            is_array($finalState) ? $finalState : [],
            is_string($streamId) ? $streamId : null,
            is_string($delivery) ? StreamingMode::tryFrom($delivery) : null,
            $record?->executionOptions,
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
