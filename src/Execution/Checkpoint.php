<?php

namespace Shahzad\AgenticGraph\Execution;

use Shahzad\AgenticGraph\Contracts\StateContract;

class Checkpoint
{
    public string $id;

    public string $graphName;

    public string $executionId;

    public StateContract $state;

    public string $currentNode;

    public array $completedNodes = [];

    public array $pendingNodes = [];

    public array $metadata = [];

    public \DateTimeImmutable $createdAt;

    public ?string $parentCheckpointId = null;
}
