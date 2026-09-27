<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tests\Support\Routers\Shipping;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Routers\AbstractRouter;

/**
 * Shares its short name with the other IntentRouter test router.
 */
class IntentRouter extends AbstractRouter
{
    public function route(StateContract $state): string
    {
        return 'end';
    }

    public function getAvailableRoutes(): array
    {
        return ['end'];
    }
}
