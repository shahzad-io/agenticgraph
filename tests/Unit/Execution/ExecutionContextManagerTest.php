<?php

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\ExecutionContextManager;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\LoopMetadata;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\ExecutionClockFactory;
use Shahzad\AgenticGraph\Tracing\Instrumentation\HttpClientInstrumentation;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceContextFactory;

beforeEach(function () {
    $this->container = app();
    $this->container->forgetInstance(TraceContext::class);
    $this->container->forgetInstance(ExecutionClock::class);
    $this->container->forgetInstance(ExecutionContext::class);
    $this->container->forgetInstance(LoopMetadata::class);
});

afterEach(function () {
    Mockery::close();
});

it('enters and binds a new execution scope', function () {
    $manager = new ExecutionContextManager(
        null,
        null,
        new TraceContextFactory,
        new ExecutionClockFactory,
        $this->container,
        new NullLogger
    );
    $executionId = 'exec-1';

    $scope = $manager->enter($executionId, 'graph', new ExecutionOptions);

    expect($scope->executionId)->toBe($executionId)
        ->and($scope->traceContext)->toBeInstanceOf(TraceContext::class)
        ->and($scope->clock)->toBeInstanceOf(ExecutionClock::class)
        ->and($scope->context)->toBeInstanceOf(ExecutionContext::class)
        ->and($this->container->bound(TraceContext::class))->toBeTrue()
        ->and($this->container->bound(ExecutionClock::class))->toBeTrue()
        ->and($this->container->bound(ExecutionContext::class))->toBeTrue();
});

it('reuses pre-bound execution context instances', function () {
    $executionId = 'exec-2';
    $traceContext = new TraceContext($executionId, 'span-root');
    $clock = new ExecutionClock($executionId);
    $context = new ExecutionContext(executionId: $executionId, startTime: 123.45);
    $this->container->instance(TraceContext::class, $traceContext);
    $this->container->instance(ExecutionClock::class, $clock);
    $this->container->instance(ExecutionContext::class, $context);

    $manager = new ExecutionContextManager(
        null,
        null,
        new TraceContextFactory,
        new ExecutionClockFactory,
        $this->container,
        new NullLogger
    );

    $scope = $manager->enter($executionId, 'graph', new ExecutionOptions);

    expect($scope->traceContext)->toBe($traceContext)
        ->and($scope->clock)->toBe($clock)
        ->and($scope->context)->toBe($context);
});

it('maps loop metadata into execution context', function () {
    $executionId = 'exec-3';
    $metadata = new LoopMetadata(
        globalIterations: 4,
        pathStack: ['A', 'B'],
        nodeVisitCounts: ['A' => 2],
        cycleCompletionCounts: ['A→B→A' => 1],
        nodeIndexMap: ['A' => [0, 2]],
        startTime: 111.5
    );

    $manager = new ExecutionContextManager(
        null,
        null,
        new TraceContextFactory,
        new ExecutionClockFactory,
        $this->container,
        new NullLogger
    );

    $scope = $manager->enter($executionId, 'graph', new ExecutionOptions, $metadata);

    expect($scope->context->globalIterations)->toBe(4)
        ->and($scope->context->pathStack)->toBe(['A', 'B'])
        ->and($scope->context->nodeVisitCounts)->toBe(['A' => 2])
        ->and($scope->context->cycleCompletionCounts)->toBe(['A→B→A' => 1])
        ->and($scope->context->nodeIndexMap)->toBe(['A' => [0, 2]])
        ->and($scope->context->startTime)->toBe(111.5)
        ->and($this->container->bound(LoopMetadata::class))->toBeTrue()
        ->and($this->container->make(LoopMetadata::class)->toArray())->toBe($metadata->toArray());
});

it('enters with an existing trace context', function () {
    $executionId = 'exec-4';
    $traceContext = new TraceContext($executionId, 'span-root');
    $manager = new ExecutionContextManager(
        null,
        null,
        new TraceContextFactory,
        new ExecutionClockFactory,
        $this->container,
        new NullLogger
    );

    $scope = $manager->enterWithTrace($executionId, 'graph', new ExecutionOptions, $traceContext);

    expect($scope->traceContext)->toBe($traceContext)
        ->and($scope->executionId)->toBe($executionId)
        ->and($this->container->make(TraceContext::class))->toBe($traceContext);
});

it('leaves loop metadata unbound when not provided', function () {
    $manager = new ExecutionContextManager(
        null,
        null,
        new TraceContextFactory,
        new ExecutionClockFactory,
        $this->container,
        new NullLogger
    );
    $scope = $manager->enter('exec-loop-none', 'graph', new ExecutionOptions);

    expect($this->container->bound(LoopMetadata::class))->toBeFalse()
        ->and($scope->context)->toBeInstanceOf(ExecutionContext::class);
});

it('exits successfully and cleans up scope bindings', function () {
    $executionId = 'exec-5';
    $exporter = Mockery::mock(TracerExporter::class);
    $exporter->shouldReceive('isEnabled')->andReturn(true);
    $exporter->shouldReceive('completeTrace')
        ->once()
        ->with($executionId, ['foo'], Mockery::type('float'), ['foo' => 'bar']);

    $manager = new ExecutionContextManager(
        $exporter,
        null,
        new TraceContextFactory,
        new ExecutionClockFactory,
        $this->container,
        new NullLogger
    );
    $scope = $manager->enter($executionId, 'graph', new ExecutionOptions);

    $manager->exit($scope, new State(['foo' => 'bar']));

    expect($this->container->bound(TraceContext::class))->toBeFalse()
        ->and($this->container->bound(ExecutionClock::class))->toBeFalse()
        ->and($this->container->bound(ExecutionContext::class))->toBeFalse();
});

it('continues cleanup when pulse export fails', function () {
    $executionId = 'exec-7';
    $exporter = Mockery::mock(TracerExporter::class);
    $exporter->shouldReceive('isEnabled')->andReturn(true);
    $exporter->shouldReceive('completeTrace')->andThrow(new RuntimeException('fail'));
    $instrumentation = Mockery::mock(HttpClientInstrumentation::class);
    $instrumentation->shouldReceive('cleanupExecution')->once()->with($executionId);
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')
        ->once()
        ->with('Execution cleanup failed', Mockery::type('array'));

    $manager = new ExecutionContextManager(
        $exporter,
        $instrumentation,
        new TraceContextFactory,
        new ExecutionClockFactory,
        $this->container,
        $logger
    );
    $scope = $manager->enter($executionId, 'graph', new ExecutionOptions);

    expect(fn () => $manager->exit($scope, new State(['foo' => 'bar'])))->not->toThrow(RuntimeException::class);

    expect($this->container->bound(TraceContext::class))->toBeFalse()
        ->and($this->container->bound(ExecutionClock::class))->toBeFalse()
        ->and($this->container->bound(ExecutionContext::class))->toBeFalse();
});

it('exits with exception and cleans up scope bindings', function () {
    $executionId = 'exec-6';
    $exporter = Mockery::mock(TracerExporter::class);
    $exporter->shouldReceive('isEnabled')->andReturn(true);
    $exporter->shouldReceive('failTrace')
        ->once()
        ->with($executionId, Mockery::type(Throwable::class), Mockery::type('float'), null);
    $instrumentation = Mockery::mock(HttpClientInstrumentation::class);
    $instrumentation->shouldReceive('cleanupExecution')->once()->with($executionId);

    $manager = new ExecutionContextManager(
        $exporter,
        $instrumentation,
        new TraceContextFactory,
        new ExecutionClockFactory,
        $this->container,
        new NullLogger
    );
    $scope = $manager->enter($executionId, 'graph', new ExecutionOptions);

    $manager->exitWithException($scope, new RuntimeException('boom'));

    expect($this->container->bound(TraceContext::class))->toBeFalse()
        ->and($this->container->bound(ExecutionClock::class))->toBeFalse()
        ->and($this->container->bound(ExecutionContext::class))->toBeFalse();
});
