<?php

namespace Shahzad\AgenticGraph\Middleware;

use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;

class ValidationMiddleware
{
    public function handle(StateContract $state, NodeInterface $node, \Closure $next): StateContract
    {
        $state->validate();
        $result = $next($state);
        $result->validate();

        return $result;
    }
}
