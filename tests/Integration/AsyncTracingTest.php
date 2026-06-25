<?php

declare(strict_types=1);

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionStarted;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamResumePayload;

class SimpleTraceNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('result', 'done');
    }
}

class StreamingTraceNode extends AbstractNode
{
    protected ?string $name = 'stream-node';

    public function execute(StateContract $state): StateContract
    {
        if ($this->isStreaming()) {
            $this->streamEvent('text_delta', ['delta' => 'job']);

            return $state->set('streamed', true);
        }

        return $state;
    }
}

class ThrowingStreamingTraceNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        if ($this->isStreaming()) {
            throw new RuntimeException('job streaming failed');
        }

        return $state;
    }
}

class CrashFailureNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        if (! $state->get('recovered', false)) {
            throw new RuntimeException('crash on node');
        }

        return $state->set('recovered', true);
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
    AgenticGraph::execute('sync-trace-test', $state, new ExecutionOptions);

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
    AgenticGraph::execute('async-trace-test', $state, new ExecutionOptions(mode: ExecutionMode::ASYNC));

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
        app(GraphExecutor::class),
        app(GraphRegistry::class)
    );

    // Verify TraceContext was created
    expect($traceContextCreated)->toBeTrue();
});

it('ExecuteGraphJob emits to broadcast sink', function () {
    $graph = GraphBuilder::create('job-stream-broadcast')
        ->addNode('stream', StreamingTraceNode::class)
        ->setEntryPoint('stream')
        ->addExitPoint('stream')
        ->build();

    AgenticGraph::register('job-stream-broadcast', $graph);

    $streamId = uniqid('stream_', true);
    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->atLeast()->once()->with(
        Mockery::on(function ($channels) use ($streamId): bool {
            return is_array($channels)
                && isset($channels[0])
                && $channels[0] === 'agenticgraph.stream.'.$streamId;
        }),
        Mockery::type('string'),
        Mockery::on(function ($payload): bool {
            if (is_array($payload) && array_key_exists('status', $payload)) {
                return true;
            }

            return is_array($payload)
                && isset($payload['stream_id'], $payload['execution_id'], $payload['seq'])
                && ! array_key_exists('type', $payload);
        })
    );
    app()->instance(Broadcaster::class, $broadcaster);

    $job = new ExecuteGraphJob(
        graphName: 'job-stream-broadcast',
        executionId: uniqid('exec_', true),
        stateData: (new State)->toArray(),
        checkpointId: null,
        rootSpanId: uniqid('span_', true),
        executionOptions: new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::BROADCAST),
        streamId: $streamId,
        streamSeq: 0
    );

    $job->handle(
        app(GraphExecutor::class),
        app(GraphRegistry::class)
    );
});

it('ExecuteGraphJob writes events to stream store', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    $store = app(StreamStoreInterface::class);

    $graph = GraphBuilder::create('job-stream-store')
        ->addNode('stream', StreamingTraceNode::class)
        ->setEntryPoint('stream')
        ->addExitPoint('stream')
        ->build();

    AgenticGraph::register('job-stream-store', $graph);

    $streamId = uniqid('stream_', true);
    $job = new ExecuteGraphJob(
        graphName: 'job-stream-store',
        executionId: uniqid('exec_', true),
        stateData: (new State)->toArray(),
        checkpointId: null,
        rootSpanId: uniqid('span_', true),
        executionOptions: new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::ITERATOR),
        streamId: $streamId,
        streamSeq: 0
    );

    $job->handle(
        app(GraphExecutor::class),
        app(GraphRegistry::class)
    );

    $events = iterator_to_array($store->readFrom($streamId, 1, 10));

    expect($events)->not->toBeEmpty()
        ->and($events[0]->streamId())->toBe($streamId);
});

it('ExecuteGraphJob includes stream id in checkpoints', function () {
    $graph = GraphBuilder::create('job-stream-checkpoint')
        ->addNode('stream', StreamingTraceNode::class)
        ->setEntryPoint('stream')
        ->addExitPoint('stream')
        ->build();

    AgenticGraph::register('job-stream-checkpoint', $graph);

    $streamId = uniqid('stream_', true);
    $executionId = uniqid('exec_', true);
    $job = new ExecuteGraphJob(
        graphName: 'job-stream-checkpoint',
        executionId: $executionId,
        stateData: (new State)->toArray(),
        checkpointId: null,
        rootSpanId: uniqid('span_', true),
        executionOptions: new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::ITERATOR),
        streamId: $streamId,
        streamSeq: 0
    );

    $job->handle(
        app(GraphExecutor::class),
        app(GraphRegistry::class)
    );

    $storage = app(StorageInterface::class);
    $list = $storage->list('job-stream-checkpoint', 5, 0);
    $checkpointId = $list[0]['id'] ?? null;

    expect($checkpointId)->not->toBeNull();
    $checkpoint = $storage->load($checkpointId);

    expect($checkpoint)->not->toBeNull()
        ->and($checkpoint->executionId)->toBe($executionId)
        ->and($checkpoint->metadata['stream_id'] ?? null)->toBe($streamId);
});

it('ExecuteGraphJob handles streaming errors', function () {
    $graph = GraphBuilder::create('job-stream-error')
        ->addNode('stream', ThrowingStreamingTraceNode::class)
        ->setEntryPoint('stream')
        ->addExitPoint('stream')
        ->build();

    AgenticGraph::register('job-stream-error', $graph);

    $job = new ExecuteGraphJob(
        graphName: 'job-stream-error',
        executionId: uniqid('exec_', true),
        stateData: (new State)->toArray(),
        checkpointId: null,
        rootSpanId: uniqid('span_', true),
        executionOptions: new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::ITERATOR),
        streamId: uniqid('stream_', true),
        streamSeq: 0
    );

    expect(function () use ($job): void {
        $job->handle(
            app(GraphExecutor::class),
            app(GraphRegistry::class)
        );
    })->toThrow(RuntimeException::class);
});

it('recovers from crash by resuming stream from checkpoint', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');

    $graph = GraphBuilder::create('job-stream-crash')
        ->addNode('stream', StreamingTraceNode::class)
        ->addNode('fail', CrashFailureNode::class)
        ->addEdge('stream', 'fail')
        ->setEntryPoint('stream')
        ->addExitPoint('fail')
        ->build();

    AgenticGraph::register('job-stream-crash', $graph);

    $streamId = uniqid('stream_', true);
    $executionId = uniqid('exec_', true);
    $options = new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::ITERATOR);

    $job = new ExecuteGraphJob(
        graphName: 'job-stream-crash',
        executionId: $executionId,
        stateData: (new State)->toArray(),
        checkpointId: null,
        rootSpanId: uniqid('span_', true),
        executionOptions: $options,
        streamId: $streamId,
        streamSeq: 0
    );

    expect(function () use ($job): void {
        $job->handle(
            app(GraphExecutor::class),
            app(GraphRegistry::class)
        );
    })->toThrow(RuntimeException::class);

    $storage = app(StorageInterface::class);
    $list = $storage->list('job-stream-crash', 5, 0);
    $checkpointId = $list[0]['id'] ?? null;

    expect($checkpointId)->not->toBeNull();
    $checkpoint = $storage->load($checkpointId);

    expect($checkpoint)->not->toBeNull()
        ->and($checkpoint->metadata['stream_id'] ?? null)->toBe($streamId)
        ->and($checkpoint->metadata['last_seq'] ?? null)->toBeInt();

    $resumePayload = new StreamResumePayload(
        fromSeq: $checkpoint->metadata['last_seq'],
        resumedAtSeq: $checkpoint->metadata['last_seq'] + 1,
        gapEvents: [],
        checkpointId: $checkpointId
    );

    $resumeState = $checkpoint->state->set('recovered', true);
    $resumeJob = new ContinueExecutionJob(
        'job-stream-crash',
        $executionId,
        $resumeState->toArray(),
        $checkpoint->currentNode,
        null,
        $options,
        $streamId,
        $checkpoint->metadata['last_seq'],
        $resumePayload
    );

    $resumeJob->handle(
        app(GraphExecutor::class),
        app(GraphRegistry::class)
    );

    $store = app(StreamStoreInterface::class);
    $events = iterator_to_array($store->readFrom($streamId, $checkpoint->metadata['last_seq'] + 1, 10));
    $types = array_map(fn ($event) => $event->type(), $events);

    expect($types)->toContain('stream_resume');
});
