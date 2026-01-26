<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Middleware;

use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;

class LoggingMiddleware
{
    public function handle(StateContract $state, NodeInterface $node, \Closure $next): StateContract
    {
        if ((bool) config('agenticgraph.logging.enabled', true)) {
            Log::debug('Executing node', ['node' => $node->getName()]);
        }
        $result = $next($state);
        if ((bool) config('agenticgraph.logging.enabled', true)) {
            Log::debug('Completed node', ['node' => $node->getName()]);
        }

        return $result;
    }
}
