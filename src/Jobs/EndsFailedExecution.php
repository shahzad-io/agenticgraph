<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Jobs;

use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamLifecycleEventTypes;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Streaming\StreamStatus;
use Shahzad\AgenticGraph\Streaming\StreamTerminalStatus;

/**
 * Job failed() handling shared by ExecuteGraphJob and ResumeExecutionJob. Each step is
 * idempotent: a failure the coordinator already handled (row failed, terminal written,
 * event fired) is left as it is.
 */
trait EndsFailedExecution
{
    /**
     * @param  array<string, mixed>  $stateData
     */
    protected function endFailedExecution(
        \Throwable $e,
        string $executionId,
        string $graphName,
        array $stateData,
        ?string $streamId,
        ?StreamingMode $streaming,
        ?ExecutionOptions $executionOptions,
        ?string $rootSpanId = null,
        ?int $fallbackSeq = null,
        ?string $reason = null,
    ): void {
        $changed = app(ExecutionRepository::class)->failed($executionId, $e->getMessage());

        if ($streamId !== null && $streaming !== null && $streaming !== StreamingMode::NONE) {
            $this->appendFailedTerminalIfMissing($e, $executionId, $stateData, $streamId, $streaming, $executionOptions ?? new ExecutionOptions, $fallbackSeq, $reason);
        }

        if ($changed) {
            event(new GraphExecutionFailed($executionId, $graphName, State::fromArray($stateData), $e, 0.0, $rootSpanId));
        }
    }

    /**
     * @param  array<string, mixed>  $stateData
     */
    private function appendFailedTerminalIfMissing(
        \Throwable $e,
        string $executionId,
        array $stateData,
        string $streamId,
        StreamingMode $streaming,
        ExecutionOptions $executionOptions,
        ?int $fallbackSeq,
        ?string $reason = null,
    ): void {
        // execution_terminal is always the last stored event, so checking the last one is enough.
        // An `interrupted` terminal ends only the run before a resume, not the execution.
        $store = app(StreamStoreInterface::class);
        $lastSeq = $store->lastSeq($streamId);
        if ($lastSeq !== null) {
            foreach ($store->readFrom($streamId, $lastSeq, 1) as $last) {
                $payload = $last->payload();
                $status = is_array($payload) ? ($payload['status'] ?? null) : null;
                if ($last->type() === StreamEventEnvelope::TYPE_EXECUTION_TERMINAL && $status !== StreamTerminalStatus::INTERRUPTED->value) {
                    return;
                }
            }
        }

        $session = new StreamSession(
            streamId: $streamId,
            executionId: $executionId,
            options: StreamOptions::fromExecution($executionOptions, delivery: $streaming->value),
            startedAtUnix: time(),
            seq: $lastSeq ?? $fallbackSeq ?? 0,
        );
        $error = ['error_type' => $e::class, 'message' => $e->getMessage()];

        $store->append(new StreamEventImpl(
            $streamId,
            $executionId,
            null,
            null,
            null,
            StreamLifecycleEventTypes::GRAPH_FAILED,
            $reason !== null ? $error + ['reason' => $reason] : $error, // reason is optional, e.g. interrupt_expired
            ++$session->seq,
            null,
            new \DateTimeImmutable,
        ));
        $store->append(StreamEventEnvelope::executionTerminal(
            $session,
            StreamTerminalStatus::FAILED,
            $stateData,
            $error,
        )->toStreamEvent());

        if ($streaming === StreamingMode::BROADCAST) {
            app(BroadcastSink::class, ['streamId' => $streamId])
                ->close(StreamStatus::ERROR, $error + ['final_state' => $stateData]);
        }
    }
}
