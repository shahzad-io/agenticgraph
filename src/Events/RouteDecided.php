<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

/**
 * Event dispatched when a conditional edge or a join decides where execution goes next.
 */
class RouteDecided
{
    /**
     * @param  string  $from  The routing node, or the sorted join sources joined with '|'
     * @param  array<int, string>  $targets  The chosen targets
     * @param  string|null  $router  Fully qualified router class, null for a join map entry
     * @param  string  $kind  llm, deterministic, fan_out or join
     */
    public function __construct(
        public readonly string $executionId,
        public readonly string $spanId,
        public readonly string $parentSpanId,
        public readonly string $from,
        public readonly array $targets,
        public readonly ?string $router,
        public readonly string $kind,
        public readonly bool $fromCache,
        public readonly bool $fallback,
        public readonly \DateTimeImmutable $startedAt,
        public readonly \DateTimeImmutable $endedAt,
    ) {}
}
