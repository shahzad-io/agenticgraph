<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\InterruptHandlerInterface;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Exceptions\ExecutionEndedException;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumption;

class ResumeExecutionJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, EndsFailedExecution, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    /** Seconds; null leaves the worker's --timeout in charge. */
    public ?int $timeout;

    public function __construct(
        public readonly int $interruptId,
        public readonly mixed $humanInput,
    ) {
        $this->onConnection(config('agenticgraph.execution.queue_connection', 'redis'));
        $this->onQueue(config('agenticgraph.execution.queue_name', 'agenticgraph'));
        $this->tries = max(1, (int) config('agenticgraph.execution.resume_tries', 1));
        $this->timeout = ((int) config('agenticgraph.execution.resume_timeout', 0)) ?: null;
    }

    public function uniqueId(): string
    {
        return 'interrupt-'.$this->interruptId;
    }

    public function handle(InterruptServiceInterface $interrupts, StreamResumption $streamResumption): void
    {
        $interrupt = InterruptRecord::find($this->interruptId);
        if (! $interrupt || $interrupt->status() !== 'resolving') {
            return;
        }

        $checkpointId = $interrupt->checkpointId();
        $metadata = $interrupt->metadata();
        if ($checkpointId === null || $checkpointId === '') {
            return;
        }
        $delivery = $metadata['stream_delivery'] ?? null;

        if (! app(ExecutionRepository::class)->exists((string) $interrupt->executionId())) {
            Log::info('AgenticGraph: job skipped, execution row is gone', ['interrupt_id' => $this->interruptId]);

            return;
        }

        try {
            if ($this->skipResume($interrupt, $checkpointId, $metadata)) {
                return;
            }

            if (is_string($delivery) && $delivery !== '') {
                $executionOptions = new ExecutionOptions;
                $executionId = $interrupt->executionId();
                if (is_string($executionId) && $executionId !== '') {
                    $record = app(ExecutionStore::class)->get($executionId);
                    if ($record !== null) {
                        $executionOptions = $record->executionOptions;
                    }
                }

                $streamOptions = StreamOptions::fromExecution(
                    $executionOptions,
                    delivery: $delivery,
                    resumeFromCheckpointId: $checkpointId,
                );
                $streamResumption->resumeStream($checkpointId, $this->humanInput, $streamOptions, mustExist: true);
            } else {
                $interrupts->resumeWithInput($checkpointId, $this->humanInput, mustExist: true);
            }

            $resolution = ['input' => $this->humanInput];
            $interrupt->resolve($resolution, $metadata['user_id'] ?? null);

            $handler = $this->resolveInterruptHandler();
            if ($handler) {
                $handler->resolved($interrupt, $resolution);
            }
        } catch (ExecutionEndedException) {
            // The execution ended elsewhere (for example expired) while this worker ran it: nothing to write or retry.
            Log::info('AgenticGraph: worker stopped, execution already ended', ['interrupt_id' => $this->interruptId]);
        } catch (\Throwable $e) {
            $interrupt->markPending([
                'last_error' => [
                    'message' => $e->getMessage(),
                    'type' => $e::class,
                    'occurred_at' => now()->toIsoString(),
                ],
            ]);

            $handler = $this->resolveInterruptHandler();
            if ($handler) {
                $handler->failed($interrupt, [
                    'error_type' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }

            Log::warning('Interrupt resume job failed', [
                'interrupt_id' => $interrupt->interruptId(),
                'execution_id' => $interrupt->executionId(),
                'checkpoint_id' => $checkpointId,
                'error_type' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * The job carries only the interrupt id, so the callback gets the state of the interrupt checkpoint. A skipped
     * resume resolves the interrupt as skipped; the interrupt handler is not told.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function skipResume(InterruptRecord $interrupt, string $checkpointId, array $metadata): bool
    {
        $executionId = (string) $interrupt->executionId();
        if (app(ExecutionRepository::class)->hasEnded($executionId)) {
            return false;
        }
        $checkpoint = app(StorageInterface::class)->load($checkpointId);
        if ($checkpoint === null) {
            return false; // The resume reports the missing checkpoint.
        }

        $delivery = $metadata['stream_delivery'] ?? null;
        $streamId = $metadata['stream_id'] ?? null;
        $lastSeq = $metadata['stream_last_seq'] ?? null;
        $skipped = $this->skipQueuedRun(
            $checkpoint->state,
            $checkpoint->graphName,
            $executionId,
            is_string($streamId) ? $streamId : null,
            is_string($delivery) ? StreamingMode::tryFrom($delivery) : null,
            is_int($lastSeq) ? $lastSeq : null,
        );
        if ($skipped) {
            $interrupt->resolve(['outcome' => 'skipped']);
        }

        return $skipped;
    }

    private function resolveInterruptHandler(): ?InterruptHandlerInterface
    {
        $handler = config('agenticgraph.interrupts.handler', null);
        if (! $handler) {
            return null;
        }

        $instance = is_string($handler) ? app($handler) : $handler;
        if (! $instance instanceof InterruptHandlerInterface) {
            return null;
        }

        return $instance;
    }

    /**
     * Ends the execution and its stream when the resume fails, for example when the graph is
     * not registered on the worker. A resumed run that failed inside the graph has already
     * been ended by its coordinator, so this then changes nothing.
     */
    public function failed(\Throwable $e): void
    {
        $interrupt = InterruptRecord::find($this->interruptId);
        $executionId = $interrupt?->executionId();
        if ($interrupt === null || ! is_string($executionId) || $executionId === '') {
            return;
        }

        $record = app(ExecutionStore::class)->get($executionId);
        $metadata = $interrupt->metadata();
        $metadata = is_array($metadata) ? $metadata : [];
        $delivery = $metadata['stream_delivery'] ?? null;
        $streamId = ($record !== null ? $record->streamId : null) ?? ($metadata['stream_id'] ?? null);
        $lastSeq = $metadata['stream_last_seq'] ?? null;
        $finalState = $record?->finalState;

        $this->endFailedExecution(
            $e,
            $executionId,
            $record !== null ? $record->graphName : (string) $interrupt->graphName(),
            is_array($finalState) ? $finalState : [],
            is_string($streamId) ? $streamId : null,
            is_string($delivery) ? StreamingMode::tryFrom($delivery) : null,
            $record?->executionOptions,
            fallbackSeq: is_int($lastSeq) ? $lastSeq : null,
        );

        // The execution is failed, so the interrupt must not stay pending: a later resolve
        // attempt then gets the existing "already resolved" result instead of a new run.
        if ($interrupt->status() !== 'resolved') {
            $interrupt->update(['metadata' => array_merge($metadata, ['resume_error' => [
                'type' => $e::class,
                'message' => $e->getMessage(),
                'occurred_at' => now()->toIsoString(),
            ]])]);
            $interrupt->resolve(['outcome' => 'failed']);
        }
    }
}
