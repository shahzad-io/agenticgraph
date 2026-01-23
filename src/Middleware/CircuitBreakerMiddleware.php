<?php

namespace Shahzad\AgenticGraph\Middleware;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;

class CircuitBreakerMiddleware
{
    private int $failureThreshold;

    private int $timeout;

    private int $retryAfter;

    public function __construct()
    {
        $this->failureThreshold = config('agenticgraph.circuit_breaker.failure_threshold', 5);
        $this->timeout = config('agenticgraph.circuit_breaker.timeout', 60);
        $this->retryAfter = config('agenticgraph.circuit_breaker.retry_after', 300);
    }

    public function handle(StateContract $state, NodeInterface $node, \Closure $next): StateContract
    {
        $key = 'circuit_breaker:'.$node->getName();
        $failures = Cache::get($key.':failures', 0);
        $openedAt = Cache::get($key.':opened_at');

        // Check if circuit is open
        if ($openedAt && (time() - $openedAt) < $this->retryAfter) {
            throw new \RuntimeException(
                "Circuit breaker is OPEN for node {$node->getName()}. ".
                'Too many recent failures. Retry after '.
                ($this->retryAfter - (time() - $openedAt)).' seconds.'
            );
        }

        try {
            $result = $next($state);

            // Success - reset failure count
            Cache::forget($key.':failures');
            Cache::forget($key.':opened_at');

            return $result;
        } catch (\Throwable $e) {
            $failures++;
            Cache::put($key.':failures', $failures, $this->timeout);

            // Open circuit if threshold exceeded
            if ($failures >= $this->failureThreshold) {
                Cache::put($key.':opened_at', time(), $this->retryAfter);
                Log::error("Circuit breaker OPENED for node {$node->getName()}", [
                    'failures' => $failures,
                    'threshold' => $this->failureThreshold,
                ]);
            }

            throw $e;
        }
    }
}
