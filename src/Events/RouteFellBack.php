<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

/**
 * Dispatched when a router's default route is taken instead of its decision. PrismRouter: the reply had no route key
 * (missing_route_key), named an unknown route (invalid_route), or every attempt failed (routing_failed). Any router:
 * it returned an empty route (empty_route) or a node the graph does not have (unknown_route).
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
