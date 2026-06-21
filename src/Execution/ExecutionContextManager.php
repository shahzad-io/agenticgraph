<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\ExecutionClockFactory;
use Shahzad\AgenticGraph\Tracing\Instrumentation\HttpClientInstrumentation;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceContextFactory;

final class ExecutionContextManager implements ExecutionScopeInterface
{
    public function __construct(
        private readonly ?PulseExporter $pulseExporter,
        private readonly ?HttpClientInstrumentation $httpClientInstrumentation,
        private readonly TraceContextFactory $traceContextFactory,
        private readonly ExecutionClockFactory $executionClockFactory,
        private readonly Container $container,
        private readonly LoggerInterface $logger
    ) {}

    public function enter(
        string $executionId,
        string $graphName,
        ExecutionOptions $options,
        ?LoopMetadata $loopMetadata = null
    ): ExecutionScope {
        $bindings = new \stdClass;
        $startTime = microtime(true);

        if (! $this->container->bound(TraceContext::class)) {
            $traceContext = $this->traceContextFactory->create($executionId);
            $this->container->instance(TraceContext::class, $traceContext);
            $bindings->{TraceContext::class} = true;
        } else {
            $traceContext = $this->container->make(TraceContext::class);
        }

        if (! $this->container->bound(ExecutionClock::class)) {
            $clock = $this->executionClockFactory->create($executionId);
            $this->container->instance(ExecutionClock::class, $clock);
            $bindings->{ExecutionClock::class} = true;
        } else {
            $clock = $this->container->make(ExecutionClock::class);
        }

        if ($loopMetadata !== null) {
            $context = ExecutionContext::fromArray($loopMetadata->toArray(), $executionId);
            $this->container->instance(ExecutionContext::class, $context);
            $bindings->{ExecutionContext::class} = true;

            $this->container->instance(LoopMetadata::class, $loopMetadata);
            $bindings->{LoopMetadata::class} = true;
        } else {
            if (! $this->container->bound(ExecutionContext::class)) {
                $context = new ExecutionContext(
                    executionId: $executionId,
                    startTime: $startTime,
                );
                $this->container->instance(ExecutionContext::class, $context);
                $bindings->{ExecutionContext::class} = true;
            } else {
                $context = $this->container->make(ExecutionContext::class);
            }
        }

        return new ExecutionScope(
            executionId: $executionId,
            traceContext: $traceContext,
            clock: $clock,
            context: $context,
            startTime: $startTime,
            bindings: $bindings,
            options: $options,
        );
    }

    public function enterWithTrace(
        string $executionId,
        string $graphName,
        ExecutionOptions $options,
        TraceContext $existingTrace,
        ?LoopMetadata $loopMetadata = null
    ): ExecutionScope {
        $bindings = new \stdClass;
        $startTime = microtime(true);

        $this->container->instance(TraceContext::class, $existingTrace);
        $bindings->{TraceContext::class} = true;

        if (! $this->container->bound(ExecutionClock::class)) {
            $clock = $this->executionClockFactory->create($executionId);
            $this->container->instance(ExecutionClock::class, $clock);
            $bindings->{ExecutionClock::class} = true;
        } else {
            $clock = $this->container->make(ExecutionClock::class);
        }

        if ($loopMetadata !== null) {
            $context = ExecutionContext::fromArray($loopMetadata->toArray(), $executionId);
            $this->container->instance(ExecutionContext::class, $context);
            $bindings->{ExecutionContext::class} = true;

            $this->container->instance(LoopMetadata::class, $loopMetadata);
            $bindings->{LoopMetadata::class} = true;
        } else {
            if (! $this->container->bound(ExecutionContext::class)) {
                $context = new ExecutionContext(
                    executionId: $executionId,
                    startTime: $startTime,
                );
                $this->container->instance(ExecutionContext::class, $context);
                $bindings->{ExecutionContext::class} = true;
            } else {
                $context = $this->container->make(ExecutionContext::class);
            }
        }

        return new ExecutionScope(
            executionId: $executionId,
            traceContext: $existingTrace,
            clock: $clock,
            context: $context,
            startTime: $startTime,
            bindings: $bindings,
            options: $options,
        );
    }

    public function exit(ExecutionScope $scope, ?StateContract $finalState = null): void
    {
        $durationMs = (microtime(true) - $scope->startTime) * 1000;

        try {
            if ($finalState !== null && $this->pulseExporter && $this->pulseExporter->isEnabled()) {
                $finalStateData = $finalState->toArray();
                $finalStateKeys = array_keys($finalStateData);
                $this->pulseExporter->completeTrace(
                    $scope->executionId,
                    $finalStateKeys,
                    $durationMs,
                    $finalStateData
                );
            }
        } catch (\Throwable $cleanupException) {
            $this->logger->warning('Execution cleanup failed', [
                'execution_id' => $scope->executionId,
                'step' => 'pulse_complete',
                'error' => $cleanupException->getMessage(),
            ]);
        }

        try {
            if ($this->httpClientInstrumentation) {
                $this->httpClientInstrumentation->cleanupExecution($scope->executionId);
            }
        } catch (\Throwable $cleanupException) {
            $this->logger->warning('Execution cleanup failed', [
                'execution_id' => $scope->executionId,
                'step' => 'http_client_cleanup',
                'error' => $cleanupException->getMessage(),
            ]);
        }

        try {
            foreach (get_object_vars($scope->bindings) as $binding => $value) {
                $this->container->forgetInstance($binding);
            }
        } catch (\Throwable $cleanupException) {
            $this->logger->warning('Execution cleanup failed', [
                'execution_id' => $scope->executionId,
                'step' => 'container_cleanup',
                'error' => $cleanupException->getMessage(),
            ]);
        }
    }

    public function exitWithException(ExecutionScope $scope, \Throwable $e): void
    {
        $durationMs = (microtime(true) - $scope->startTime) * 1000;

        try {
            if ($this->pulseExporter && $this->pulseExporter->isEnabled()) {
                $this->pulseExporter->failTrace($scope->executionId, $e, $durationMs, null);
            }
        } catch (\Throwable $cleanupException) {
            $this->logger->warning('Execution cleanup failed', [
                'execution_id' => $scope->executionId,
                'step' => 'pulse_fail',
                'error' => $cleanupException->getMessage(),
            ]);
        }

        try {
            if ($this->httpClientInstrumentation) {
                $this->httpClientInstrumentation->cleanupExecution($scope->executionId);
            }
        } catch (\Throwable $cleanupException) {
            $this->logger->warning('Execution cleanup failed', [
                'execution_id' => $scope->executionId,
                'step' => 'http_client_cleanup',
                'error' => $cleanupException->getMessage(),
            ]);
        }

        try {
            foreach (get_object_vars($scope->bindings) as $binding => $value) {
                $this->container->forgetInstance($binding);
            }
        } catch (\Throwable $cleanupException) {
            $this->logger->warning('Execution cleanup failed', [
                'execution_id' => $scope->executionId,
                'step' => 'container_cleanup',
                'error' => $cleanupException->getMessage(),
            ]);
        }
    }
}
