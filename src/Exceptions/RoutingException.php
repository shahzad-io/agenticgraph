<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Exceptions;

use RuntimeException;

/**
 * A PrismRouter with no default route got no usable decision: the reply had no route key (missing_route_key), named
 * an unknown route (invalid_route), or every attempt failed (routing_failed; the reply is the last error).
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
            "{$router} could not route ({$reason}) and has no default route. Reply: ".mb_strimwidth($reply, 0, 500, '...'),
            0,
            $previous,
        );
    }
}
