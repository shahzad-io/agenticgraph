<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Middleware;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;

/**
 * Circuit Breaker Middleware with half-open state support.
 *
 * States:
 * - CLOSED: Normal operation, requests pass through
 * - OPEN: Circuit is tripped, requests fail fast
 * - HALF_OPEN: Testing if service has recovered
 */
class CircuitBreakerMiddleware
{
    private const STATE_CLOSED = 'closed';

    private const STATE_OPEN = 'open';

    private const STATE_HALF_OPEN = 'half_open';

    private int $failureThreshold;

    private int $timeout;

    private int $retryAfter;

    private int $halfOpenRequests;

    public function __construct()
    {
        $this->failureThreshold = config('agenticgraph.circuit_breaker.failure_threshold', 5);
        $this->timeout = config('agenticgraph.circuit_breaker.timeout', 60);
        $this->retryAfter = config('agenticgraph.circuit_breaker.retry_after', 300);
        $this->halfOpenRequests = config('agenticgraph.circuit_breaker.half_open_requests', 3);
    }

    public function handle(StateContract $state, NodeInterface $node, \Closure $next): StateContract
    {
        $key = 'circuit_breaker:'.$node->getName();
        $currentState = $this->getState($key);

        return match ($currentState) {
            self::STATE_OPEN => $this->handleOpenState($key, $node, $next, $state),
            self::STATE_HALF_OPEN => $this->handleHalfOpenState($key, $node, $next, $state),
            default => $this->handleClosedState($key, $node, $next, $state),
        };
    }

    /**
     * Handle CLOSED state - normal operation.
     */
    private function handleClosedState(string $key, NodeInterface $node, \Closure $next, StateContract $state): StateContract
    {
        try {
            $result = $next($state);

            // Success - reset failure count
            $this->recordSuccess($key);

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($key, $node);
            throw $e;
        }
    }

    /**
     * Handle OPEN state - fail fast or transition to half-open.
     */
    private function handleOpenState(string $key, NodeInterface $node, \Closure $next, StateContract $state): StateContract
    {
        // Check if we should attempt to transition to half-open
        if ($this->shouldAttemptReset($key)) {
            return $this->transitionToHalfOpen($key, $node, $next, $state);
        }

        // Circuit is still open, fail fast
        $openedAt = Cache::get($key.':opened_at');
        $remainingSeconds = $openedAt ? ($this->retryAfter - (time() - $openedAt)) : $this->retryAfter;

        throw new \RuntimeException(
            "Circuit breaker is OPEN for node {$node->getName()}. ".
            "Too many recent failures. Retry after {$remainingSeconds} seconds."
        );
    }

    /**
     * Handle HALF_OPEN state - test if service has recovered.
     */
    private function handleHalfOpenState(string $key, NodeInterface $node, \Closure $next, StateContract $state): StateContract
    {
        // Check if we've exceeded the number of test requests in half-open state
        $testRequests = Cache::get($key.':test_requests', 0);
        if ($testRequests >= $this->halfOpenRequests) {
            throw new \RuntimeException(
                "Circuit breaker is HALF_OPEN for node {$node->getName()}. ".
                'Maximum test requests reached. Waiting for more time before retrying.'
            );
        }

        // Increment test request counter
        Cache::put($key.':test_requests', $testRequests + 1, $this->retryAfter);

        try {
            $result = $next($state);

            // Success in half-open state - check if we should close the circuit
            $consecutiveSuccesses = Cache::get($key.':consecutive_successes', 0) + 1;
            Cache::put($key.':consecutive_successes', $consecutiveSuccesses, $this->retryAfter);

            if ($consecutiveSuccesses >= $this->halfOpenRequests) {
                // Enough consecutive successes, close the circuit
                $this->closeCircuit($key);

                if ((bool) config('agenticgraph.logging.enabled', true)) {
                    Log::info("Circuit breaker CLOSED for node {$node->getName()} after {$consecutiveSuccesses} consecutive successes");
                }
            }

            return $result;
        } catch (\Throwable $e) {
            // Failure in half-open state - reopen the circuit
            $this->openCircuit($key, $node);
            throw $e;
        }
    }

    /**
     * Get the current circuit state.
     */
    private function getState(string $key): string
    {
        $state = Cache::get($key.':state', self::STATE_CLOSED);
        $openedAt = Cache::get($key.':opened_at');

        // If state is OPEN but timeout has passed, transition to HALF_OPEN
        if ($state === self::STATE_OPEN && $openedAt && (time() - $openedAt) >= $this->retryAfter) {
            return self::STATE_HALF_OPEN;
        }

        return $state;
    }

    /**
     * Check if we should attempt to reset the circuit (transition from OPEN to HALF_OPEN).
     */
    private function shouldAttemptReset(string $key): bool
    {
        $openedAt = Cache::get($key.':opened_at');

        return $openedAt !== null && (time() - $openedAt) >= $this->retryAfter;
    }

    /**
     * Transition from OPEN to HALF_OPEN state.
     */
    private function transitionToHalfOpen(string $key, NodeInterface $node, \Closure $next, StateContract $state): StateContract
    {
        Cache::put($key.':state', self::STATE_HALF_OPEN, $this->retryAfter);
        Cache::put($key.':test_requests', 0, $this->retryAfter);
        Cache::put($key.':consecutive_successes', 0, $this->retryAfter);

        if ((bool) config('agenticgraph.logging.enabled', true)) {
            Log::info("Circuit breaker HALF_OPEN for node {$node->getName()} - testing recovery");
        }

        return $this->handleHalfOpenState($key, $node, $next, $state);
    }

    /**
     * Record a successful request.
     */
    private function recordSuccess(string $key): void
    {
        // Reset failure count on success
        Cache::forget($key.':failures');
    }

    /**
     * Record a failed request.
     */
    private function recordFailure(string $key, NodeInterface $node): void
    {
        $failures = Cache::get($key.':failures', 0) + 1;
        Cache::put($key.':failures', $failures, $this->timeout);

        // Open circuit if threshold exceeded
        if ($failures >= $this->failureThreshold) {
            $this->openCircuit($key, $node);
        }
    }

    /**
     * Open the circuit.
     */
    private function openCircuit(string $key, NodeInterface $node): void
    {
        Cache::put($key.':state', self::STATE_OPEN, $this->retryAfter);
        Cache::put($key.':opened_at', time(), $this->retryAfter);
        Cache::forget($key.':consecutive_successes');
        Cache::forget($key.':test_requests');

        if ((bool) config('agenticgraph.logging.enabled', true)) {
            Log::error("Circuit breaker OPENED for node {$node->getName()}", [
                'failures' => Cache::get($key.':failures', 0),
                'threshold' => $this->failureThreshold,
            ]);
        }
    }

    /**
     * Close the circuit.
     */
    private function closeCircuit(string $key): void
    {
        Cache::put($key.':state', self::STATE_CLOSED, $this->timeout);
        Cache::forget($key.':opened_at');
        Cache::forget($key.':failures');
        Cache::forget($key.':consecutive_successes');
        Cache::forget($key.':test_requests');
    }

    /**
     * Get circuit breaker metrics for a node.
     *
     * @return array{state: string, failures: int, opened_at: int|null}
     */
    public function getMetrics(string $nodeName): array
    {
        $key = 'circuit_breaker:'.$nodeName;
        $state = $this->getState($key);
        $openedAt = Cache::get($key.':opened_at');

        return [
            'state' => $state,
            'failures' => Cache::get($key.':failures', 0),
            'opened_at' => $openedAt,
            'retry_after_seconds' => $this->retryAfter,
        ];
    }

    /**
     * Reset the circuit breaker for a node.
     */
    public function reset(string $nodeName): void
    {
        $key = 'circuit_breaker:'.$nodeName;
        $this->closeCircuit($key);
    }
}
