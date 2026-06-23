<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Exceptions\InterruptPersistenceDisabledException;
use Shahzad\AgenticGraph\Jobs\ResumeExecutionJob;
use Shahzad\AgenticGraph\State\State;
use Illuminate\Support\Facades\Route;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamDescriptor;
use Shahzad\AgenticGraph\Streaming\StreamHandle;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumption;

final class ExecutionHandle
{
    private ExecutionStatus $status;

    private ?StateContract $finalState;

    public function __construct(
        private readonly string $executionId,
        private readonly ExecutionOptions $options,
        private readonly GraphExecutor $executor,
        private readonly ExecutionStore $store,
        private readonly ExecutionStatusResolver $resolver,
        private readonly CheckpointManager $checkpoints,
        private readonly ?StreamHandle $streamHandle = null,
        ?ExecutionStatus $status = null,
        ?StateContract $finalState = null,
        private readonly ?string $streamId = null
    ) {
        $this->status = $status ?? ExecutionStatus::RUNNING;
        $this->finalState = $finalState;
    }

    public function id(): string
    {
        return $this->executionId;
    }

    public function streamId(): ?string
    {
        if ($this->streamId !== null) {
            return $this->streamId;
        }

        return $this->streamHandle?->streamId();
    }

    public function status(): ExecutionStatus
    {
        return $this->status;
    }

    public function isSync(): bool
    {
        return $this->options->mode === ExecutionMode::SYNC;
    }

    public function isAsync(): bool
    {
        return $this->options->mode === ExecutionMode::ASYNC;
    }

    public function refresh(): ExecutionHandle
    {
        $resolved = $this->resolver->resolve($this->executionId);
        if ($resolved !== null) {
            $this->status = $resolved;
        }
        if (in_array($this->status, [ExecutionStatus::COMPLETED, ExecutionStatus::INTERRUPTED], true) && $this->finalState === null) {
            $this->finalState = $this->loadPersistedFinalState();
        }

        return $this;
    }

    public function wait(?int $timeout = null): ExecutionHandle
    {
        if ($this->isSync()) {
            return $this;
        }

        $start = microtime(true);
        while (true) {
            $this->refresh();
            if (in_array($this->status, [ExecutionStatus::COMPLETED, ExecutionStatus::FAILED, ExecutionStatus::INTERRUPTED], true)) {
                return $this;
            }
            if ($timeout !== null && (microtime(true) - $start) >= $timeout) {
                return $this;
            }
            usleep(100000);
        }
    }

    public function stream(): StreamDescriptor
    {
        $mode = $this->options->streaming;

        if ($mode === StreamingMode::NONE) {
            return StreamDescriptor::none();
        }

        if ($this->options->mode === ExecutionMode::SYNC && $mode === StreamingMode::ITERATOR) {
            return new StreamDescriptor(
                streamId: $this->streamId(),
                streamUrl: null,
                inline: $this->streamHandle?->getIterator(),
                mode: $mode,
            );
        }

        if ($this->options->mode === ExecutionMode::ASYNC) {
            $streamId = $this->resolveStreamId();

            return new StreamDescriptor(
                streamId: $streamId,
                streamUrl: $this->resolveStreamUrl($streamId),
                inline: null,
                mode: $mode,
            );
        }

        return new StreamDescriptor(
            streamId: null,
            streamUrl: null,
            inline: null,
            mode: $mode,
        );
    }

    private function resolveStreamId(): ?string
    {
        $streamId = $this->streamId();
        if ($streamId !== null && $streamId !== '') {
            return $streamId;
        }

        $record = $this->store->get($this->executionId);

        return $record?->streamId;
    }

    private function resolveStreamUrl(?string $streamId): ?string
    {
        if ($streamId === null || $streamId === '') {
            return null;
        }

        if (! config('agenticgraph.streaming.routes.enabled', true)) {
            return null;
        }

        if (! Route::has(StreamDescriptor::ROUTE_NAME)) {
            return null;
        }

        return route(StreamDescriptor::ROUTE_NAME, ['streamId' => $streamId]);
    }

    public function getFinalState(): StateContract
    {
        if (! in_array($this->status, [ExecutionStatus::COMPLETED, ExecutionStatus::INTERRUPTED], true)) {
            throw new NotYetCompleteException('Execution is not complete.');
        }
        if ($this->finalState !== null) {
            return $this->finalState;
        }

        $finalState = $this->loadPersistedFinalState();
        if ($finalState !== null) {
            return $finalState;
        }

        throw new \RuntimeException('Final state is unavailable.');
    }

    public function latestState(): ?StateContract
    {
        if ($this->finalState !== null) {
            return $this->finalState;
        }

        return $this->loadPersistedFinalState();
    }

    public function checkpoint(): ?Checkpoint
    {
        return $this->checkpoints->getLatestForExecution($this->executionId);
    }

    public function replayFrom(int $cursor, int $limit = 100): \Iterator
    {
        $streamId = $this->streamId();
        if ($streamId === null) {
            $record = $this->store->get($this->executionId);
            $streamId = $record?->streamId;
        }
        if ($streamId === null) {
            return new \ArrayIterator([]);
        }

        $fromSeq = max(1, $cursor + 1);

        return app(StreamStoreInterface::class)->readFrom($streamId, $fromSeq, $limit);
    }

    public function isInterrupted(): bool
    {
        return $this->status === ExecutionStatus::INTERRUPTED;
    }

    public function pendingInterrupt(): ?InterruptRecord
    {
        return InterruptRecord::query()
            ->forExecution($this->executionId)
            ->pending()
            ->latest('id')
            ->first();
    }

    public function resume(mixed $input): ExecutionHandle
    {
        if ($this->isAsync()) {
            if (! (bool) config('agenticgraph.interrupts.persistence_enabled', true)) {
                throw new InterruptPersistenceDisabledException;
            }

            $interrupt = $this->pendingInterrupt();
            if ($interrupt === null) {
                return $this->refresh();
            }

            $interrupt->markResolving();
            $interruptId = $interrupt->interruptId();
            if ($interruptId === null) {
                throw new \RuntimeException('Interrupt id is missing.');
            }
            dispatch(new ResumeExecutionJob($interruptId, $input));

            return new self(
                executionId: $this->executionId,
                options: $this->options,
                executor: $this->executor,
                store: $this->store,
                resolver: $this->resolver,
                checkpoints: $this->checkpoints,
                status: ExecutionStatus::RUNNING,
                streamId: $this->streamId(),
            );
        }

        $pending = $this->pendingInterrupt();
        $checkpointId = $pending?->checkpointId();

        if ($checkpointId === null && $this->finalState !== null && $this->finalState->hasInterrupt()) {
            $checkpointId = $this->finalState->getInterruptCheckpointId();
        }

        $checkpoint = $checkpointId !== null
            ? $this->checkpoints->load($checkpointId)
            : $this->checkpoints->getLatestForExecution($this->executionId);
        if ($checkpoint === null) {
            throw new \RuntimeException('No interrupt checkpoint found for execution.');
        }

        if ($this->options->streaming === StreamingMode::NONE) {
            $state = $this->executor->resumeWithInput($checkpoint->id, $input);
            $this->resolvePendingInterruptForCheckpoint($checkpoint->id, $input);
            $status = $state->hasInterrupt() ? ExecutionStatus::INTERRUPTED : ExecutionStatus::COMPLETED;

            return new self(
                executionId: $checkpoint->executionId,
                options: $this->options,
                executor: $this->executor,
                store: $this->store,
                resolver: $this->resolver,
                checkpoints: $this->checkpoints,
                status: $status,
                finalState: $status === ExecutionStatus::COMPLETED ? $state : null
            );
        }

        $streamOptions = new StreamOptions(
            delivery: $this->options->streaming->value,
            interrupts: $this->options->interrupts
        );
        $handle = app(StreamResumption::class)->resumeStream($checkpoint->id, $input, $streamOptions);
        $this->resolvePendingInterruptForCheckpoint($checkpoint->id, $input);
        $state = $handle->getFinalState();
        $status = $state->hasInterrupt() ? ExecutionStatus::INTERRUPTED : ExecutionStatus::COMPLETED;

        return new self(
            executionId: $handle->executionId(),
            options: $this->options,
            executor: $this->executor,
            store: $this->store,
            resolver: $this->resolver,
            checkpoints: $this->checkpoints,
            streamHandle: $handle,
            status: $status,
            finalState: $status === ExecutionStatus::COMPLETED ? $state : null,
            streamId: $handle->streamId()
        );
    }

    private function resolvePendingInterruptForCheckpoint(string $checkpointId, mixed $input): void
    {
        $interrupt = InterruptRecord::query()
            ->where('checkpoint_id', $checkpointId)
            ->whereIn('status', ['pending', 'resolving'])
            ->latest('id')
            ->first();

        if ($interrupt === null) {
            return;
        }

        $metadata = $interrupt->metadata();
        $interrupt->resolve(['input' => $input], $metadata['user_id'] ?? null);
    }

    private function loadPersistedFinalState(): ?StateContract
    {
        $record = $this->store->get($this->executionId);
        if ($record?->finalState !== null) {
            return State::fromArray($record->finalState);
        }

        if ($this->status !== ExecutionStatus::INTERRUPTED) {
            return null;
        }

        $pending = $this->pendingInterrupt();
        if ($pending?->checkpointId() !== null) {
            $checkpoint = $this->checkpoints->load($pending->checkpointId());
            if ($checkpoint !== null) {
                $state = $checkpoint->state;
                if ($state->hasInterrupt()) {
                    return $state;
                }

                $reason = $pending->reason() ?? $checkpoint->metadata['interrupt_reason'] ?? 'Interrupted';

                return $state->setInterrupt($reason, $checkpoint->id);
            }
        }

        $checkpoint = $this->checkpoints->getLatestForExecution($this->executionId);
        if ($checkpoint === null) {
            return null;
        }

        $state = $checkpoint->state;
        if ($state->hasInterrupt()) {
            return $state;
        }

        $reason = $checkpoint->metadata['interrupt_reason'] ?? 'Interrupted';

        return $state->setInterrupt($reason, $checkpoint->id);
    }
}
