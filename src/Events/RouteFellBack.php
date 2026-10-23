<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

/**
 * Dispatched when PrismRouter takes its default route instead of a decision: the reply had no route key
 * (missing_route_key), named an unknown route (invalid_route), or every attempt failed (routing_failed).
 */
final class RouteFellBack
{
    public function __construct(
        public readonly string $router,
        public readonly string $route,
        public readonly string $reason,
        /** The last attempt's error message for routing_failed; null otherwise. */
        public readonly ?string $error = null,
    ) {}
}
