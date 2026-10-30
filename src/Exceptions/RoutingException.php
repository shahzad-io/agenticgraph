<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Exceptions;

use RuntimeException;

/**
 * A router with no usable default route gave no usable decision. PrismRouter: the reply had no route key
 * (missing_route_key), named an unknown route (invalid_route), or every attempt failed (routing_failed; the reply is
 * the last error). Any router: it returned an empty route (empty_route) or a node the graph does not have
 * (unknown_route; a fan-out naming one always throws), and its default route is unset or not a node.
 */
final class RoutingException extends RuntimeException
{
    public function __construct(
        public readonly string $router,
        public readonly string $reason,
        public readonly string $reply,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "{$router} could not route ({$reason}) and has no usable default route. Reply: ".mb_strimwidth($reply, 0, 500, '...'),
            0,
            $previous,
        );
    }
}
