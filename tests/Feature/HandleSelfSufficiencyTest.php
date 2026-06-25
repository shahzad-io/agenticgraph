<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery as MockeryFacade;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;

class SelfSufficiencyStreamNode extends AbstractNode
{
    protected ?string $name = 'stream';

    public function execute(StateContract $state): StateContract
    {
        if ($this->isStreaming()) {
            $this->streamEvent('text_delta', ['delta' => 'hello']);

            return $state->set('streamed', true);
        }

        return $state;
    }
}

class SelfSufficiencyApprovalNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('processed', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->has('approved');
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Needs approval';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $this->handleApproval(
            $state,
            $humanInput['approved'] ?? false,
            $humanInput['comments'] ?? null
        );
    }
}

class SelfSufficiencyFailureNode extends AbstractNode
{
    protected ?string $name = 'fail';

    public function execute(StateContract $state): StateContract
    {
        throw new RuntimeException('handle self-sufficiency failure');
    }
}

function registerSelfSufficiencyStreamGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('stream', SelfSufficiencyStreamNode::class)
        ->setEntryPoint('stream')
        ->addExitPoint('stream')
        ->build();

    AgenticGraph::register($name, $graph);
}

function registerSelfSufficiencyApprovalGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('approve', SelfSufficiencyApprovalNode::class)
        ->setEntryPoint('approve')
        ->addExitPoint('approve')
        ->build();

    AgenticGraph::register($name, $graph);
}

function registerSelfSufficiencyFailureGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('fail', SelfSufficiencyFailureNode::class)
        ->setEntryPoint('fail')
        ->addExitPoint('fail')
        ->build();

    AgenticGraph::register($name, $graph);
}

function runSelfSufficiencyAsyncJob(string $executionId): void
{
    $job = null;

    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $dispatched) use ($executionId, &$job): bool {
        if ($dispatched->executionId !== $executionId) {
            return false;
        }

        $job = $dispatched;

        return true;
    });

    expect($job)->toBeInstanceOf(ExecuteGraphJob::class);
    $job->handle(app(GraphExecutor::class), app(GraphRegistry::class));
}

beforeEach(function () {
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    config()->set('agenticgraph.streaming.store.driver', 'database');
    app()->forgetInstance(StreamStoreInterface::class);
});

afterEach(function () {
    MockeryFacade::close();
});

it('reconstructs streamId on getExecution handles from the persisted execution record', function () {
    Bus::fake();

    $broadcaster = MockeryFacade::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->atLeast()->once();
    app()->instance(Broadcaster::class, $broadcaster);

    $graphName = 't3a-stream-reconstruct-'.Str::uuid();
    registerSelfSufficiencyStreamGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::BROADCAST)
    );

    $liveStreamId = $handle->streamId();
    expect($liveStreamId)->not->toBeNull();

    runSelfSufficiencyAsyncJob($handle->id());

    $reconstructed = AgenticGraph::getExecution($handle->id());

    expect($reconstructed->streamId())->toBe($liveStreamId);
});

it('resolves streamId from handle stream descriptor and interruptDetails via one chain', function () {
    $graphName = 't3a-stream-single-source-'.Str::uuid();
    registerSelfSufficiencyApprovalGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR)
    );

    iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));

    $liveStreamId = $handle->streamId();
    expect($liveStreamId)->not->toBeNull();

    $reconstructed = AgenticGraph::getExecution($handle->id())->refresh();
    $details = AgenticGraph::interruptDetails($handle->id());
    $descriptor = $reconstructed->stream();

    expect($reconstructed->streamId())->toBe($liveStreamId)
        ->and($descriptor->streamId)->toBe($liveStreamId)
        ->and($details->streamId)->toBe($liveStreamId);
});

it('applies pending interrupt metadata stream_id override on a reconstructed handle', function () {
    $graphName = 't3a-stream-metadata-override-'.Str::uuid();
    registerSelfSufficiencyApprovalGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR)
    );

    iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));

    $record = $handle->pendingInterrupt();
    expect($record)->not->toBeNull();

    $overrideStreamId = 'stream-override-'.Str::uuid();
    $metadata = $record->metadata();
    $metadata['stream_id'] = $overrideStreamId;
    $record->update(['metadata' => $metadata]);

    $reconstructed = AgenticGraph::getExecution($handle->id())->refresh();

    expect($reconstructed->streamId())->toBe($overrideStreamId)
        ->and(AgenticGraph::interruptDetails($handle->id())->streamId)->toBe($overrideStreamId);
});

it('exposes failure messages via error() and null otherwise', function () {
    $graphName = 't3b-error-accessor-'.Str::uuid();
    registerSelfSufficiencyFailureGraph($graphName);

    try {
        AgenticGraph::execute(
            $graphName,
            new State,
            new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE)
        );
        expect(false)->toBeTrue('Expected execution to fail');
    } catch (RuntimeException) {
    }

    $executionId = (string) DB::table('agenticgraph_executions')
        ->where('graph_name', $graphName)
        ->orderByDesc('created_at')
        ->value('id');

    expect($executionId)->not->toBe('');

    $failed = AgenticGraph::getExecution($executionId)->refresh();
    $completedGraph = 't3b-error-complete-'.Str::uuid();
    registerSelfSufficiencyStreamGraph($completedGraph);
    $completed = AgenticGraph::execute(
        $completedGraph,
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE)
    );

    expect($failed->error())->toBe('handle self-sufficiency failure')
        ->and($completed->error())->toBeNull();
});

it('returns broadcastChannel from config prefix and streamId only for broadcast mode', function () {
    config()->set('agenticgraph.streaming.broadcast.channel_prefix', 'custom.stream.');

    Bus::fake();

    $graphName = 't3c-broadcast-channel-'.Str::uuid();
    registerSelfSufficiencyStreamGraph($graphName);

    $broadcastHandle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::BROADCAST)
    );

    $streamId = $broadcastHandle->streamId();
    expect($streamId)->not->toBeNull()
        ->and($broadcastHandle->broadcastChannel())->toBe('custom.stream.'.$streamId);

    $noneHandle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE)
    );

    expect($noneHandle->broadcastChannel())->toBeNull();
});

it('uses the same broadcast channel prefix in BroadcastSink as broadcastChannel()', function () {
    config()->set('agenticgraph.streaming.broadcast.channel_prefix', 'unified.prefix.');

    $capturedChannel = null;
    $broadcaster = MockeryFacade::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')
        ->once()
        ->with(
            MockeryFacade::on(function (array $channels) use (&$capturedChannel): bool {
                $capturedChannel = $channels[0] ?? null;

                return is_string($capturedChannel);
            }),
            MockeryFacade::type('string'),
            MockeryFacade::type('array')
        );

    $sink = new BroadcastSink($broadcaster, 'stream-unified');

    $sink->emit(new StreamEventImpl(
        streamId: 'stream-unified',
        executionId: 'exec-unified',
        nodeName: null,
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'x'],
        seq: 1,
        nodeSeq: null,
        occurredAt: new DateTimeImmutable
    ));

    expect($capturedChannel)->toBe('unified.prefix.stream-unified');
});

it('exposes status predicates that match execution status honestly', function () {
    Bus::fake();

    $graphName = 't3d-predicates-'.Str::uuid();
    registerSelfSufficiencyApprovalGraph($graphName);

    $interrupted = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE)
    );

    expect($interrupted->isInterrupted())->toBeTrue()
        ->and($interrupted->isCompleted())->toBeFalse()
        ->and($interrupted->isFailed())->toBeFalse()
        ->and($interrupted->isRunning())->toBeFalse()
        ->and($interrupted->isQueued())->toBeFalse();

    $completed = $interrupted->resume(['approved' => true, 'comments' => 'ok']);

    expect($completed->isCompleted())->toBeTrue()
        ->and($completed->isInterrupted())->toBeFalse()
        ->and($completed->isFailed())->toBeFalse()
        ->and($completed->isRunning())->toBeFalse()
        ->and($completed->isQueued())->toBeFalse();

    $queuedGraph = 't3d-queued-'.Str::uuid();
    registerSelfSufficiencyStreamGraph($queuedGraph);
    $queued = AgenticGraph::execute(
        $queuedGraph,
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::NONE)
    );

    expect($queued->isQueued())->toBeTrue()
        ->and($queued->isRunning())->toBeFalse();

    $failureGraph = 't3d-failed-'.Str::uuid();
    registerSelfSufficiencyFailureGraph($failureGraph);

    try {
        AgenticGraph::execute(
            $failureGraph,
            new State,
            new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE)
        );
    } catch (RuntimeException) {
    }

    $failedId = (string) DB::table('agenticgraph_executions')
        ->where('graph_name', $failureGraph)
        ->orderByDesc('created_at')
        ->value('id');

    $failed = AgenticGraph::getExecution($failedId)->refresh();

    expect($failed->isFailed())->toBeTrue()
        ->and($failed->isCompleted())->toBeFalse();
});
