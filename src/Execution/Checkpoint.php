<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Shahzad\AgenticGraph\Contracts\StateContract;

class Checkpoint
{
    public string $id;

    public string $graphName;

    public string $executionId;

    public StateContract $state;

    public string $currentNode;

    /** @var array<string, mixed> */
    public array $metadata = [];

    public \DateTimeImmutable $createdAt;
}
