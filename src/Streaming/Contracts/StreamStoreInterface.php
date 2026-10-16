<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Contracts;

interface StreamStoreInterface
{
    public function append(StreamEvent $event): void;

    public function readFrom(string $streamId, int $fromSeq, int $limit = 200): \Generator;

    public function lastSeq(string $streamId): ?int;

    /**
     * Oldest stored seq, or null when the stream has no events. Retention only trims from
     * the front, so a cursor below this is gone.
     */
    public function firstSeq(string $streamId): ?int;

    public function truncate(string $streamId, int $beforeSeq): void;
}
