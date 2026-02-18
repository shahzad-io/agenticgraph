<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Contracts;

interface StreamStoreInterface
{
    public function append(StreamEvent $event): void;

    public function readFrom(string $streamId, int $fromSeq, int $limit = 200): \Generator;

    public function lastSeq(string $streamId): ?int;

    public function truncate(string $streamId, int $beforeSeq): void;
}
