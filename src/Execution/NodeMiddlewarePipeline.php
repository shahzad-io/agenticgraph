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

final class NodeMiddlewarePipeline
{
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

    public function execute(
        StateContract $state,
        NodeInterface $node,
        string $executionId,
        string $nodeName,
        ?string $spanId,
    ): StateContract {
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
            function (StateContract $state) use ($node, $executionId, $nodeName, $spanId) {
                $attempts = 0;
                $max = $node->getRetryAttempts();
                $delay = $node->getRetryDelay();

                while (true) {
                    $attemptStart = microtime(true);
                    if ($this->container->bound(StreamContext::class)) {
                        $this->container->make(StreamContext::class)->resetNodeAttemptEmission();
                    }

                    try {
                        $node->onBefore($state);
                        $result = $node->execute($state);
                        $node->onAfter($state, $result);

                        return $result;
                    } catch (\Throwable $e) {
                        $attempts++;
                        $durationMs = (microtime(true) - $attemptStart) * 1000;
                        $willRetry = $attempts <= $max && $node->shouldRetry($e);

                        if ($this->container->bound(StreamContext::class)) {
                            $streamContext = $this->container->make(StreamContext::class);
                            if ($streamContext->isStreamingActive() && $streamContext->nodeAttemptHasContentEmission()) {
                                $willRetry = false;
                            }
                        }

                        $startedAt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $attemptStart)) ?: new \DateTimeImmutable;
                        $traceContext = $this->resolveTraceContext();
                        $parentSpanId = $traceContext instanceof TraceContext ? $traceContext->getParentSpanId() : null;

                        $this->events->dispatch(new NodeExecutionFailed(
                            $executionId,
                            $spanId,
                            $nodeName,
                            $state,
                            $e,
                            $durationMs,
                            $attempts,
                            $willRetry,
                            $startedAt,
                            $parentSpanId
                        ));
                        $node->onError($e, $state);

                        if (! $willRetry) {
                            throw $e;
                        }

                        usleep($delay * 1000);
                    }
                }
            }
        );

        return $pipeline($state);
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

        return $this->container->bound(TraceContext::class)
            ? $this->container->make(TraceContext::class)
            : null;
    }
}
