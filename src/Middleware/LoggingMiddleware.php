<?php

namespace Shahzad\AgenticGraph\Middleware;

use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;

class LoggingMiddleware
{
    public function handle(StateContract $state, NodeInterface $node, \Closure $next): StateContract
    {
        Log::debug('Executing node', ['node' => $node->getName()]);
        $result = $next($state);
        Log::debug('Completed node', ['node' => $node->getName()]);

        return $result;
    }
}
