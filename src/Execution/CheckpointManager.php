<?php

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Support\Str;
use Shahzad\AgenticGraph\Contracts\StorageInterface;

class CheckpointManager
{
    public function __construct(private StorageInterface $storage) {}

    public function save(string $executionId, Checkpoint $checkpoint): string
    {
        $checkpoint->executionId = $executionId;
        if (! isset($checkpoint->id) || $checkpoint->id === '') {
            $checkpoint->id = Str::uuid()->toString();
        }
        $checkpoint->createdAt = new \DateTimeImmutable;

        return $this->storage->save($checkpoint);
    }
}
