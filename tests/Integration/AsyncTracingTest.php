<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionStarted;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

class SimpleTraceNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('result', 'done');
    }
}

beforeEach(function () {
    config(['agenticgraph.tracing.enabled' => true]);
});

it('dispatches tracing events for executeWorkflow', function () {
    // Track events
    $events = [];
    Event::listen(GraphExecutionStarted::class, function ($event) use (&$events) {
        $events[] = ['type' => 'GraphExecutionStarted', 'spanId' => $event->spanId];
    });
    Event::listen(NodeExecutionStarted::class, function ($event) use (&$events) {
        $events[] = ['type' => 'NodeExecutionStarted', 'spanId' => $event->spanId];
    });
    Event::listen(NodeExecutionCompleted::class, function ($event) use (&$events) {
        $events[] = ['type' => 'NodeExecutionCompleted', 'spanId' => $event->spanId];
    });

    // Create simple graph
    $graph = GraphBuilder::create('sync-trace-test')
        ->addNode('start', SimpleTraceNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register('sync-trace-test', $graph);

    // Execute sync workflow
    $state = new State(['input' => 'test']);
    AgenticGraph::executeWorkflow('sync-trace-test', $state);

    // Verify events were dispatched with span IDs
    expect($events)->toHaveCount(3);
    expect($events[0]['type'])->toBe('GraphExecutionStarted');
    expect($events[0]['spanId'])->not->toBeNull();
    expect($events[1]['type'])->toBe('NodeExecutionStarted');
    expect($events[1]['spanId'])->not->toBeNull();
    expect($events[2]['type'])->toBe('NodeExecutionCompleted');
    expect($events[2]['spanId'])->not->toBeNull();
});

it('executeAsyncWorkflow dispatches job with rootSpanId', function () {
    Bus::fake();

    // Create simple graph
    $graph = GraphBuilder::create('async-trace-test')
        ->addNode('start', SimpleTraceNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register('async-trace-test', $graph);

    // Execute async workflow
    $state = new State(['input' => 'test']);
    $executionId = AgenticGraph::executeAsyncWorkflow('async-trace-test', $state);

    // Verify job was dispatched with rootSpanId
    Bus::assertDispatched(ExecuteGraphJob::class, function ($job) {
        // The fix ensures rootSpanId is passed to the job
        return $job->rootSpanId !== null;
    });
});

it('ExecuteGraphJob sets up tracing context before execution', function () {
    // This test verifies the fix by checking that ExecuteGraphJob
    // properly sets up TraceContext before calling execute()

    $graph = GraphBuilder::create('job-trace-test')
        ->addNode('start', SimpleTraceNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register('job-trace-test', $graph);

    $state = new State(['input' => 'test']);
    $executionId = uniqid('exec_', true);
    $rootSpanId = uniqid('span_', true);

    // Create the job
    $job = new ExecuteGraphJob(
        graphName: 'job-trace-test',
        executionId: $executionId,
        stateData: $state->toArray(),
        checkpointId: null,
        rootSpanId: $rootSpanId
    );

    // Track if TraceContext was created
    $traceContextCreated = false;
    Event::listen(NodeExecutionStarted::class, function ($event) use (&$traceContextCreated) {
        // If this event has a spanId, it means TraceContext was properly set up
        $traceContextCreated = $event->spanId !== null;
    });

    // Execute the job
    $job->handle(
        app(\Shahzad\AgenticGraph\Execution\GraphExecutor::class),
        app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)
    );

    // Verify TraceContext was created
    expect($traceContextCreated)->toBeTrue();
});
