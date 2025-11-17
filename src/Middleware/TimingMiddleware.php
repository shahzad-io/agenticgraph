<?php

namespace Shahzad\AgenticGraph\Middleware;

use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\NodeExecutionTimed;

class TimingMiddleware
{
    public function handle(StateContract $state, NodeInterface $node, \Closure $next): StateContract
    {
        $start = microtime(true);
        $result = $next($state);
        $duration = microtime(true) - $start;
        event(new NodeExecutionTimed($node->getName(), $duration));
        return $result;
    }
}