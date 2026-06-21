<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamHandle;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumption;
use Symfony\Component\HttpFoundation\Response;

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
            $record = $this->store->get($this->executionId);
            if ($record?->finalState !== null) {
                $this->finalState = State::fromArray($record->finalState);
            }
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

    public function stream(): \Iterator|Response|null
    {
        if ($this->streamHandle === null) {
            return null;
        }

        return match ($this->options->streaming) {
            StreamingMode::SSE => $this->streamHandle->response(),
            StreamingMode::ITERATOR => $this->streamHandle->getIterator(),
            default => null,
        };
    }

    public function getFinalState(): StateContract
    {
        if (! in_array($this->status, [ExecutionStatus::COMPLETED, ExecutionStatus::INTERRUPTED], true)) {
            throw new NotYetCompleteException('Execution is not complete.');
        }
        if ($this->finalState !== null) {
            return $this->finalState;
        }
        $record = $this->store->get($this->executionId);
        if ($record?->finalState !== null) {
            return State::fromArray($record->finalState);
        }
        throw new \RuntimeException('Final state is unavailable.');
    }

    public function latestState(): ?StateContract
    {
        if ($this->finalState !== null) {
            return $this->finalState;
        }

        $record = $this->store->get($this->executionId);
        if ($record?->finalState !== null) {
            return State::fromArray($record->finalState);
        }

        return $this->checkpoints->getLatestForExecution($this->executionId)?->state;
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
        $checkpointId = null;
        if ($this->finalState !== null && $this->finalState->hasInterrupt()) {
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

        return new self(
            executionId: $handle->executionId(),
            options: $this->options,
            executor: $this->executor,
            store: $this->store,
            resolver: $this->resolver,
            checkpoints: $this->checkpoints,
            streamHandle: $handle,
            status: ExecutionStatus::COMPLETED,
            finalState: $handle->getFinalState(),
            streamId: $handle->streamId()
        );
    }
}
