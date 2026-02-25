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
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumption;

class ResumeExecutionJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $interruptId,
        public readonly mixed $humanInput,
    ) {
        $this->onConnection(config('agenticgraph.execution.queue_connection'));
        $this->onQueue(config('agenticgraph.execution.queue_name'));
    }

    public function uniqueId(): string
    {
        return 'interrupt-'.$this->interruptId;
    }

    public function handle(GraphExecutor $executor, StreamResumption $streamResumption): void
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

        try {
            if (is_string($delivery) && $delivery !== '') {
                $streamOptions = new StreamOptions(
                    delivery: $delivery,
                    interrupts: true,
                    resumeFromCheckpointId: $checkpointId
                );
                $streamResumption->resumeStream($checkpointId, $this->humanInput, $streamOptions);
            } else {
                $executor->resumeWithInput($checkpointId, $this->humanInput);
            }

            $resolution = ['input' => $this->humanInput];
            $interrupt->resolve($resolution, $metadata['user_id'] ?? null);

            $handler = $this->resolveInterruptHandler();
            if ($handler) {
                $handler->resolved($interrupt, $resolution);
            }
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

    private function resolveInterruptHandler(): ?InterruptHandlerInterface
    {
        $handler = config('agenticgraph.interrupts.handler');
        if (! $handler) {
            return null;
        }

        $instance = is_string($handler) ? app($handler) : $handler;
        if (! $instance instanceof InterruptHandlerInterface) {
            return null;
        }

        return $instance;
    }
}
