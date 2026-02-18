<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

interface ExecutionStore
{
    public function store(ExecutionRecord $record): void;

    public function get(string $executionId): ?ExecutionRecord;

    public function updateStatus(string $executionId, ExecutionStatus $status, mixed $finalState = null): void;
}
