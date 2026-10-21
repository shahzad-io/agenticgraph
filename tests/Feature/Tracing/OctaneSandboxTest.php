<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;

/**
 * Octane serves each request from a clone of the booted app (the sandbox), but the event dispatcher keeps the
 * booted app as its container. A listener the dispatcher builds must still send to the sandbox's exporter.
 */
it('sends graph events to the exporter of the running request, not the booted app', function () {
    config(['agenticgraph.tracing.enabled' => true]);
    $booted = app();
    $booted->instance(TracerExporter::class, Mockery::mock(TracerExporter::class)->shouldIgnoreMissing());

    $sandbox = clone $booted;
    $requestExporter = Mockery::mock(TracerExporter::class)->shouldIgnoreMissing();
    $requestExporter->shouldReceive('isEnabled')->andReturn(true);
    $requestExporter->shouldReceive('startTrace')->once();
    $sandbox->instance(TracerExporter::class, $requestExporter);
    Container::setInstance($sandbox);

    try {
        event(new GraphExecutionStarted('exec_sandbox', 'sandbox-graph', new State, new DateTimeImmutable));
    } finally {
        Container::setInstance($booted);
    }
});
