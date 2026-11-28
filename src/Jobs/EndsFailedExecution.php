<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Jobs;

use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\AgenticGraph;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\GraphExecutionSkipped;
use Shahzad\AgenticGraph\Exceptions\QueuedRunSkippedException;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamLifecycleEventTypes;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Streaming\StreamSinkFactory;
use Shahzad\AgenticGraph\Streaming\StreamStatus;
use Shahzad\AgenticGraph\Streaming\StreamTerminalStatus;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\BufferedTraceExporter;

/**
 * How a job or command ends a run from outside its coordinator: job failed() handling (ExecuteGraphJob,
 * ResumeExecutionJob), the expire commands, and the skip check of all four graph jobs. Each step is
 * idempotent: a failure the coordinator already handled (row failed, terminal written, event fired)
 * is left as it is.
 *
 * The order is fixed: the row's end is stored first, then the stream frames, then the event. No app listener runs
 * before the end is committed, so a listener that throws cannot undo it.
 */
trait EndsFailedExecution
{
    /**
     * A job's failed(). An exception from the event's listener goes to the caller; the run stays ended.
     *
     * @param  array<string, mixed>  $stateData
     * @param  (\Closure(): mixed)|null  $onEnded  the caller's own part of the end, stored before the event fires
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
        ?\Closure $onEnded = null,
    ): void {
        if (! app(ExecutionRepository::class)->exists($executionId)) {
            Log::info('AgenticGraph: failure not recorded, execution row is gone', ['execution_id' => $executionId]);
            $onEnded?->__invoke();

            return; // No row, stream event or failure event for a deleted run.
        }

        $changed = app(ExecutionRepository::class)->failed($executionId, $e->getMessage());
        $onEnded?->__invoke();

        if ($streamId !== null && $streaming !== null && $streaming !== StreamingMode::NONE) {
            $this->appendFailedTerminalIfMissing($e, $executionId, $stateData, $streamId, $streaming, $executionOptions ?? new ExecutionOptions, $fallbackSeq);
        }

        if ($changed) {
            event(new GraphExecutionFailed($executionId, $graphName, State::fromArray($stateData), $e, 0.0, $rootSpanId));
        }
    }

    /**
     * The stored part of an expiry, inside the command's transaction. True only for the call that changed the row;
     * ended_by commits with the status.
     */
    protected function failExpiredExecution(\Throwable $e, string $executionId, string $reason): bool
    {
        $repo = app(ExecutionRepository::class);
        $changed = $repo->failed($executionId, $e->getMessage());
        if ($changed) {
            $repo->markEndedByExpiry($executionId, $reason); // stale_queued, stale_running or interrupt_expired: final, see reopen().
        }

        return $changed;
    }

    /**
     * The rest of an expiry, after the commit and only for the call that changed the row: the trace segment is opened,
     * then the stream frames, then GraphExecutionFailed. The end is stored by now, so what throws here is reported once
     * and the command goes on.
     *
     * @param  array<string, mixed>  $stateData
     */
    protected function announceExpiredExecution(
        \Throwable $e,
        string $executionId,
        string $graphName,
        array $stateData,
        ?string $streamId,
        ?StreamingMode $streaming,
        ?ExecutionOptions $executionOptions,
        ?string $rootSpanId,
        ?int $fallbackSeq,
        string $reason,
    ): void {
        // One failed segment that continues the original trace: its root span id, the graph name, timestamps after
        // every earlier segment. Inside a caller's own transaction it is sent only after that commit.
        rescue(function () use ($executionId, $graphName): void {
            $exporter = $this->boundTraceExporter();
            if ($exporter?->isEnabled()) {
                $exporter->resumeTrace($executionId, $graphName, 'continued');
                if ($exporter instanceof BufferedTraceExporter) {
                    $exporter->sendAfterCommit($executionId);
                }
            }
        });

        if ($streamId !== null && $streaming !== null && $streaming !== StreamingMode::NONE) {
            rescue(fn () => $this->appendFailedTerminalIfMissing($e, $executionId, $stateData, $streamId, $streaming, $executionOptions ?? new ExecutionOptions, $fallbackSeq, $reason));
        }

        rescue(fn () => event(new GraphExecutionFailed($executionId, $graphName, State::fromArray($stateData), $e, 0.0, $rootSpanId, $reason)));
    }

    private function boundTraceExporter(): ?TracerExporter
    {
        return app()->bound(TracerExporter::class) ? app(TracerExporter::class) : null;
    }

    /**
     * Asks the skipQueuedRunsUsing() callback. True: it skipped the run, which now ends as failed with ended_by=skip,
     * with the usual failed frames (reason `skipped`) and no GraphExecutionFailed, and GraphExecutionSkipped fires once,
     * from the call that changed the row. The caller stops. The stream values of the job are used where the row has none.
     *
     * @param  (\Closure(): mixed)|null  $onEnded  the caller's own part of the end, stored before the event fires
     */
    protected function skipQueuedRun(
        StateContract $state,
        string $graphName,
        string $executionId,
        ?string $streamId = null,
        ?StreamingMode $streaming = null,
        ?int $fallbackSeq = null,
        ?\Closure $onEnded = null,
    ): bool {
        $callback = app()->bound(AgenticGraph::QUEUED_RUN_SKIPPER) ? app(AgenticGraph::QUEUED_RUN_SKIPPER) : null;
        $reason = $callback instanceof \Closure ? $callback($state, $graphName, $executionId) : null;
        if ($reason === null) {
            return false;
        }
        if (! is_string($reason)) {
            throw new \UnexpectedValueException('The skipQueuedRunsUsing() callback must return null or a string reason, got '.get_debug_type($reason).'.');
        }

        $repo = app(ExecutionRepository::class);
        $changed = $repo->skipped($executionId, $reason);
        $onEnded?->__invoke(); // The caller's own part of the end, stored before the event fires.
        if ($changed) {
            $record = app(ExecutionStore::class)->get($executionId);
            $options = $record !== null ? $record->executionOptions ?? new ExecutionOptions : new ExecutionOptions;
            $streaming ??= $options->streaming;
            if ($streaming === StreamingMode::AUTO) {
                $streaming = app(GraphExecutor::class)->resolveStreamingMode($options);
            }
            $streamId = $record->streamId ?? $streamId;
            if ($streamId !== null && $streaming !== StreamingMode::NONE) {
                $this->appendFailedTerminalIfMissing(new QueuedRunSkippedException($executionId), $executionId, State::toStoragePayload($state), $streamId, $streaming, $options, $fallbackSeq, QueuedRunSkippedException::REASON, reasonOnTerminal: true);
            }
        } elseif ($repo->getStatus($executionId) === 'failed') {
            $repo->markEndedBySkip($executionId); // Crash recovery of a failed run: it keeps its error and terminal.
        }

        Log::info('AgenticGraph: queued run skipped', ['execution_id' => $executionId, 'graph_name' => $graphName, 'reason' => $reason]);

        if ($changed) {
            // Like GraphExecutionFailed: after the row update and the stream frames, and a listener that throws fails the job.
            event(new GraphExecutionSkipped($executionId, $graphName, $reason));
        }

        return true;
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
        bool $reasonOnTerminal = false,
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
        $error = StreamEventEnvelope::clientError($e);
        $withReason = $reason !== null ? $error + ['reason' => $reason] : $error; // reason is optional, e.g. interrupt_expired
        // A skip also names itself on the terminal and stream_close: a broadcast client gets no graph_failed live.
        $closing = $reasonOnTerminal ? $withReason : $error;

        $store->append(new StreamEventImpl(
            $streamId,
            $executionId,
            null,
            null,
            null,
            StreamLifecycleEventTypes::GRAPH_FAILED,
            $withReason,
            ++$session->seq,
            null,
            new \DateTimeImmutable,
        ));
        $terminal = StreamEventEnvelope::executionTerminal(
            $session,
            StreamTerminalStatus::FAILED,
            $stateData,
            $closing,
        )->toStreamEvent();
        $store->append($terminal);

        // The terminal is stored; the live terminal and close are best effort, so a broadcaster that is down
        // (for example Reverb) does not stop the failure from being recorded.
        if ($streaming === StreamingMode::BROADCAST) {
            try {
                $sink = app(StreamSinkFactory::class)->broadcastSink($streamId);
                $sink->emit($terminal);
                $sink->close(StreamStatus::ERROR, $closing + ['final_state' => $stateData]);
            } catch (\Throwable $broadcastError) {
                Log::warning('AgenticGraph: stream close broadcast failed', [
                    'execution_id' => $executionId,
                    'stream_id' => $streamId,
                    'error_type' => $broadcastError::class,
                    'message' => $broadcastError->getMessage(),
                ]);
            }
        }
    }
}
