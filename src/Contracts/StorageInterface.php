<?php

namespace Shahzad\AgenticGraph\Contracts;

use Shahzad\AgenticGraph\Execution\Checkpoint;

interface StorageInterface
{
    public function save(Checkpoint $checkpoint): string;

    public function load(string $id): ?Checkpoint;

    public function list(string $graphName, int $limit = 50): array;

    public function delete(string $id): bool;

    public function cleanup(int $olderThanDays = 30): int;
}
