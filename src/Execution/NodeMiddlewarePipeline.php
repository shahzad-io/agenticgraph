<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\NodeExecutionFailed;
use Shahzad\AgenticGraph\Middleware\LoggingMiddleware;
use Shahzad\AgenticGraph\Middleware\TimingMiddleware;
use Shahzad\AgenticGraph\Middleware\ValidationMiddleware;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceTime;

final class NodeMiddlewarePipeline
{
    /** @var array{spanId: ?string, attempt: int, attemptOf: ?string, startedAt: float}|null */
    private ?array $lastAttempt = null;

    /**
     * @param  array<int, class-string|object>|null  $globalMiddleware
     * @param  \Closure(): ?TraceContext|null  $traceContextProvider
     */
    public function __construct(
        private readonly Container $container,
        private readonly Dispatcher $events,
        private readonly ?array $globalMiddleware = null,
        private readonly ?\Closure $traceContextProvider = null,
    ) {}

    /**
     * The attempt that ran last: the successful one, or the final failure.
     *
     * @return array{spanId: ?string, attempt: int, attemptOf: ?string, startedAt: float}|null
     */
    public function lastAttempt(): ?array
    {
        return $this->lastAttempt;
    }

    public function execute(
        StateContract $state,
        NodeInterface $node,
        string $executionId,
        string $nodeName,
        ?string $spanId,
    ): StateContract {
        $this->lastAttempt = null;
        $pipelineStart = microtime(true);
        $reported = null;
        $entered = false;
        $stack = [];
        foreach ($this->resolveMiddleware() as $middleware) {
            $stack[] = is_string($middleware) ? $this->container->make($middleware) : $middleware;
        }

        $pipeline = array_reduce(
            array_reverse($stack),
            function ($next, $middleware) use ($node) {
                return function (StateContract $state) use ($middleware, $node, $next) {
                    return $middleware->handle($state, $node, $next);
                };
            },
            function (StateContract $state) use ($node, $executionId, $nodeName, $spanId, &$reported, &$entered) {
                $entered = true;
                $reported = null;
                $attempt = 0;
                $max = $node->getRetryAttempts();
                $delay = $node->getRetryDelay();
                $traceContext = $this->resolveTraceContext();
                $attemptSpanId = $spanId;

                while (true) {
                    $attempt++;
                    // Each retry is its own span; children of the attempt parent it through the current span.
                    if ($attempt > 1 && $spanId !== null && $traceContext instanceof TraceContext) {
                        $traceContext->endSpan();
                        $attemptSpanId = $traceContext->startSpan($nodeName);
                    }
                    $attemptStart = microtime(true);
                    $this->lastAttempt = [
                        'spanId' => $attemptSpanId,
                        'attempt' => $attempt,
                        'attemptOf' => $spanId,
                        'startedAt' => $attemptStart,
                    ];
                    if ($this->container->bound(StreamContext::class)) {
                        $streamContext = $this->container->make(StreamContext::class);
                        $streamContext->resetNodeAttemptEmission();
                        $streamContext->enterNode($nodeName);
                    }

                    try {
                        $node->onBefore($state);
                        $result = $node->execute($state);
                        $node->onAfter($state, $result);

                        return $result;
                    } catch (\Throwable $e) {
                        $durationMs = (microtime(true) - $attemptStart) * 1000;
                        $willRetry = $attempt <= $max && $node->shouldRetry($e);

                        if ($this->container->bound(StreamContext::class)) {
                            $streamContext = $this->container->make(StreamContext::class);
                            if ($streamContext->isStreamingActive() && $streamContext->nodeAttemptHasContentEmission()) {
                                $willRetry = false;
                            }
                        }

                        $this->events->dispatch(new NodeExecutionFailed(
                            $executionId,
                            $attemptSpanId,
                            $nodeName,
                            $state,
                            $e,
                            $durationMs,
                            $attempt,
                            $willRetry,
                            TraceTime::fromMicrotime($attemptStart),
                            $traceContext instanceof TraceContext ? $traceContext->getRootSpanId() : null,
                            $node::class,
                            $spanId,
                            'node',
                        ));
                        $node->onError($e, $state);

                        if (! $willRetry) {
                            $reported = $e;

                            throw $e;
                        }

                        usleep($delay * 1000);
                    }
                }
            }
        );

        try {
            $result = $pipeline($state);
            $this->reportProgress($executionId);

            return $result;
        } catch (\Throwable $e) {
            // A middleware failed outside the retry loop (before $next, or after it returned):
            // record it as a failed attempt so the node still has a span. When the node's own
            // failure was already reported, its span stands; a middleware that rethrows or
            // replaces that exception only changes what the run fails with.
            if ($reported === null) {
                $attempt = $this->lastAttempt ?? ['spanId' => $spanId, 'attempt' => 1, 'attemptOf' => $spanId, 'startedAt' => $pipelineStart];
                $traceContext = $this->resolveTraceContext();

                $this->events->dispatch(new NodeExecutionFailed(
                    $executionId,
                    $attempt['spanId'],
                    $nodeName,
                    $state,
                    $e,
                    (microtime(true) - $attempt['startedAt']) * 1000,
                    $attempt['attempt'],
                    false,
                    TraceTime::fromMicrotime($attempt['startedAt']),
                    $traceContext instanceof TraceContext ? $traceContext->getRootSpanId() : null,
                    $node::class,
                    $attempt['attemptOf'],
                    $entered ? 'middleware_after' : 'middleware_before',
                ));
            }

            throw $e;
        }
    }

    /**
     * A node completed: tell the execution row, so a long run that keeps making progress is never expired as stale.
     * Sequential, streamed, parallel inline and queued branch nodes all pass through here. One update of a running row.
     */
    private function reportProgress(string $executionId): void
    {
        try {
            $this->container->make(ExecutionRepository::class)->touch($executionId);
        } catch (\Throwable) {
            // Best effort.
        }
    }

    /**
     * @return array<int, class-string|object>
     */
    private function resolveMiddleware(): array
    {
        return $this->globalMiddleware ?? config('agenticgraph.middleware.global', [
            LoggingMiddleware::class,
            TimingMiddleware::class,
            ValidationMiddleware::class,
        ]);
    }

    private function resolveTraceContext(): ?TraceContext
    {
        if ($this->traceContextProvider !== null) {
            return ($this->traceContextProvider)();
        }

        return TraceContext::tryGet();
    }
}
