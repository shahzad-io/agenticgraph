<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Middleware;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Tracing\TraceContext;

class LoggingMiddleware
{
    public function handle(StateContract $state, NodeInterface $node, \Closure $next): StateContract
    {
        $traceContext = null;
        try {
            $traceContext = app(TraceContext::class);
        } catch (BindingResolutionException $e) {
            // TraceContext not bound, tracing not active
        }

        if ((bool) config('agenticgraph.logging.enabled', true)) {
            Log::debug('Executing node', [
                'node' => $node->getName(),
                'execution_id' => $traceContext?->getExecutionId(),
                'span_id' => $traceContext?->getSpanId(),
            ]);
        }
        $result = $next($state);
        if ((bool) config('agenticgraph.logging.enabled', true)) {
            Log::debug('Completed node', [
                'node' => $node->getName(),
                'execution_id' => $traceContext?->getExecutionId(),
                'span_id' => $traceContext?->getSpanId(),
            ]);
        }

        return $result;
    }
}
