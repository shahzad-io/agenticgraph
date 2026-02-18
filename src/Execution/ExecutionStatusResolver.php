<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

final class ExecutionStatusResolver
{
    public function __construct(
        private readonly ExecutionStore $store,
        private readonly CheckpointManager $checkpoints
    ) {}

    public function resolve(string $executionId): ?ExecutionStatus
    {
        $record = $this->store->get($executionId);
        if ($record === null) {
            return null;
        }

        if ($record->status === ExecutionStatus::RUNNING || $record->status === ExecutionStatus::QUEUED) {
            $latest = $this->checkpoints->getLatestForExecution($executionId);
            if ($latest !== null && ($latest->metadata['interrupted'] ?? false) === true) {
                return ExecutionStatus::INTERRUPTED;
            }
        }

        return $record->status;
    }
}
