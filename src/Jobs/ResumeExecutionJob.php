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
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Exceptions\ExecutionEndedException;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\ResolvesFailedInterrupt;
use Shahzad\AgenticGraph\Execution\RunEndEvent;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumption;

class ResumeExecutionJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, EndsFailedExecution, InteractsWithQueue, Queueable, ResolvesFailedInterrupt, SerializesModels;

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
        $this->withinJobScope(fn () => $this->run($interrupts, $streamResumption));
    }

    private function run(InterruptServiceInterface $interrupts, StreamResumption $streamResumption): void
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

        $executionId = (string) $interrupt->executionId();
        $resolution = ['input' => $this->humanInput];
        $completed = false; // This resume completed the run: set before its completion listeners run.
        $handlerTold = false;

        try {
            if ($this->skipResume($interrupt, $checkpointId, $metadata)) {
                return;
            }

            if (is_string($delivery) && $delivery !== '') {
                $executionOptions = new ExecutionOptions;
                if ($executionId !== '') {
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
                $resume = fn () => $streamResumption->resumeStream($checkpointId, $this->humanInput, $streamOptions);
            } else {
                $resume = fn () => $interrupts->resumeWithInput($checkpointId, $this->humanInput);
            }

            // The run's end is stored with its interrupt before any listener of that end runs.
            RunEndEvent::beforeListeners(
                $executionId,
                function (GraphExecutionCompleted|GraphExecutionFailed $event) use ($interrupt, $resolution, $metadata, &$completed): void {
                    if ($event instanceof GraphExecutionFailed) {
                        $this->resolveAsFailed($interrupt, $event->exception);

                        return;
                    }
                    $completed = true;
                    $this->resolveWithInput($interrupt, $resolution, $metadata);
                },
                fn () => ExecutionRepository::asQueuedRun($executionId, $resume),
            );

            $this->resolveWithInput($interrupt, $resolution, $metadata); // The run paused again, with no end event.
            $handlerTold = true;
            $this->resolveInterruptHandler()?->resolved($interrupt, $resolution);
        } catch (ExecutionEndedException) {
            // The execution ended elsewhere (for example expired) while this worker ran it: nothing to write or retry.
            Log::info('AgenticGraph: worker stopped, execution already ended', ['interrupt_id' => $this->interruptId]);
        } catch (\Throwable $e) {
            // A skip has stored its whole end, interrupt included, so only what came after it failed (for example a
            // GraphExecutionSkipped listener): the job fails, and the interrupt stays resolved as skipped.
            if (($interrupt->getAttribute('resolution')['outcome'] ?? null) === 'skipped') {
                throw $e;
            }

            // An ended run never gets a pending interrupt again.
            $status = app(ExecutionRepository::class)->getStatus($executionId);
            if ($status === 'completed') {
                // What failed came after the end, for example a completion listener.
                if ($completed) {
                    rescue(function () use ($interrupt, $resolution, $metadata, $handlerTold): void {
                        $this->resolveWithInput($interrupt, $resolution, $metadata);
                        if (! $handlerTold) {
                            $this->resolveInterruptHandler()?->resolved($interrupt, $resolution);
                        }
                    });
                }
                $this->logResumeFailure($interrupt, $checkpointId, $e);
                $this->failJobOfCompletedRun($executionId, $e);

                throw $e;
            }
            if ($status === 'failed') {
                $this->resolveAsFailed($interrupt, $e);
            } else {
                $interrupt->markPending(['last_error' => $this->errorDetails($e)]);
            }

            $handler = $this->resolveInterruptHandler();
            if ($handler) {
                $handler->failed($interrupt, [
                    'error_type' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }

            $this->logResumeFailure($interrupt, $checkpointId, $e);

            throw $e;
        }
    }

    private function logResumeFailure(InterruptRecord $interrupt, string $checkpointId, \Throwable $e): void
    {
        Log::warning('Interrupt resume job failed', [
            'interrupt_id' => $interrupt->interruptId(),
            'execution_id' => $interrupt->executionId(),
            'checkpoint_id' => $checkpointId,
            'error_type' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }

    /**
     * @param  array{input: mixed}  $resolution
     * @param  array<string, mixed>  $metadata
     */
    private function resolveWithInput(InterruptRecord $interrupt, array $resolution, array $metadata): void
    {
        if ($interrupt->status() !== 'resolved') {
            $interrupt->resolve($resolution, $metadata['user_id'] ?? null);
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

        return $this->skipQueuedRun(
            $checkpoint->state,
            $checkpoint->graphName,
            $executionId,
            is_string($streamId) ? $streamId : null,
            is_string($delivery) ? StreamingMode::tryFrom($delivery) : null,
            is_int($lastSeq) ? $lastSeq : null,
            fn () => $interrupt->resolve(['outcome' => 'skipped']),
        );
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
            // The execution is failed, so the interrupt must not stay pending: a later resolve
            // attempt then gets the existing "already resolved" result instead of a new run.
            onEnded: fn () => $this->resolveAsFailed($interrupt, $e),
        );
    }
}
