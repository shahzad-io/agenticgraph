<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;

class JobFailureInsideGraphNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        throw new RuntimeException('provider error inside node');
    }
}

class JobFailureRecordingBroadcaster implements Broadcaster
{
    /** @var list<array{event: string, payload: array<string, mixed>}> */
    public array $sent = [];

    public function auth($request)
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result)
    {
        return null;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $this->sent[] = ['event' => $event, 'payload' => $payload];
    }
}

/**
 * A queued execution whose graph is not registered on the worker, as AsyncStreamCoordinator leaves it.
 */
function queuedJobForMissingGraph(StreamingMode $streaming = StreamingMode::SSE): ExecuteGraphJob
{
    $executionId = 'exec_'.uniqid();
    $streamId = 'stream_'.uniqid();
    $options = new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: $streaming);
    app(ExecutionRepository::class)->queued($executionId, 'not-on-worker', ['topic' => 'x'], $streamId, $options);

    return new ExecuteGraphJob(
        graphName: 'not-on-worker',
        executionId: $executionId,
        stateData: ['topic' => 'x'],
        rootSpanId: 'span_root',
        executionOptions: $options,
        streamId: $streamId,
        streamSeq: 0,
    );
}

/**
 * @return list<StreamEvent>
 */
function jobFailureStreamEvents(string $streamId): array
{
    return array_values(iterator_to_array(app(StreamStoreInterface::class)->readFrom($streamId, 1, 100), false));
}

function jobFailureTypes(string $streamId): array
{
    return array_map(fn (StreamEvent $event) => $event->type(), jobFailureStreamEvents($streamId));
}

beforeEach(function () {
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    config()->set('agenticgraph.streaming.store.driver', 'database');
    app()->forgetInstance(StreamStoreInterface::class);
    Event::fake([GraphExecutionFailed::class]);
});

it('fails the execution and ends the stream once when the graph is missing on the worker', function () {
    $job = queuedJobForMissingGraph();

    expect(fn () => dispatch($job))->toThrow(RuntimeException::class, "Graph 'not-on-worker' not found");

    $row = DB::table('agenticgraph_executions')->where('id', $job->executionId)->first();
    $events = jobFailureStreamEvents($job->streamId);

    expect($row->status)->toBe('failed')
        ->and($row->error)->toContain("Graph 'not-on-worker' not found")
        ->and(jobFailureTypes($job->streamId))->toBe(['graph_failed', 'execution_terminal'])
        ->and(array_map(fn (StreamEvent $event) => $event->seq(), $events))->toBe([1, 2])
        ->and($events[1]->payload()['status'])->toBe('failed')
        ->and($events[1]->payload()['final_state'])->toBeNull()
        ->and($events[1]->payload()['meta']['error_type'])->toBe('ExecutionFailed')
        ->and($events[1]->payload()['meta']['message'])->toBe('Execution failed');

    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
    Event::assertDispatched(GraphExecutionFailed::class, fn (GraphExecutionFailed $event) => $event->executionId === $job->executionId
        && $event->graphName === 'not-on-worker');
});

it('does nothing more when failed() runs a second time', function () {
    $job = queuedJobForMissingGraph();

    try {
        dispatch($job);
    } catch (RuntimeException $e) {
        $job->failed($e);
    }

    expect(jobFailureTypes($job->streamId))->toBe(['graph_failed', 'execution_terminal'])
        ->and(DB::table('agenticgraph_executions')->where('id', $job->executionId)->value('status'))->toBe('failed');

    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
});

it('adds nothing when the coordinator already handled a failure inside the graph', function () {
    AgenticGraph::register('job-failure-inside', GraphBuilder::create('job-failure-inside')
        ->addNode('boom', JobFailureInsideGraphNode::class)
        ->setEntryPoint('boom')
        ->addExitPoint('boom')
        ->build());

    try {
        AgenticGraph::execute('job-failure-inside', new State, new ExecutionOptions(
            mode: ExecutionMode::ASYNC,
            streaming: StreamingMode::SSE,
        ));
        expect(false)->toBeTrue('expected the job to fail');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('provider error inside node');
    }

    $row = DB::table('agenticgraph_executions')->where('graph_name', 'job-failure-inside')->first();
    $types = jobFailureTypes($row->stream_id);

    // Exactly what the coordinator wrote (terminal last, F12); failed() appended nothing.
    expect($row->status)->toBe('failed')
        ->and($types)->toBe(['graph_start', 'node_start', 'node_error', 'graph_failed', 'execution_terminal']);

    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
});

it('sends one broadcast stream_close when a broadcast job fails before the graph starts', function () {
    $broadcaster = new JobFailureRecordingBroadcaster;
    app()->instance(Broadcaster::class, $broadcaster);
    $job = queuedJobForMissingGraph(StreamingMode::BROADCAST);

    try {
        dispatch($job);
    } catch (RuntimeException $e) {
        $job->failed($e);
    }

    $closes = array_values(array_filter($broadcaster->sent, fn (array $sent) => $sent['event'] === 'stream_close'));

    expect($closes)->toHaveCount(1)
        ->and($closes[0]['payload']['status'])->toBe('error')
        ->and($closes[0]['payload']['meta']['final_state'])->toBeNull()
        ->and($closes[0]['payload']['meta']['error_type'])->toBe('ExecutionFailed');
});

it('reads tries and timeout from config with defaults when the keys are missing', function () {
    $execution = config('agenticgraph.execution');
    unset($execution['tries'], $execution['timeout']);
    config()->set('agenticgraph.execution', $execution);

    $defaults = queuedJobForMissingGraph();

    config()->set('agenticgraph.execution.tries', '3');
    config()->set('agenticgraph.execution.timeout', '120');
    $configured = queuedJobForMissingGraph();

    expect($defaults->tries)->toBe(1)
        ->and($defaults->timeout)->toBeNull()
        ->and($configured->tries)->toBe(3)
        ->and($configured->timeout)->toBe(120);
});
