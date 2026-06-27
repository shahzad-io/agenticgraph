<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Bus;
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
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Sinks\NullTransportSink;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Streaming\StreamSinkFactory;

class AsyncStoreOnlyStreamNode extends AbstractNode
{
    protected ?string $name = 'async-stream';

    public function execute(StateContract $state): StateContract
    {
        if ($this->isStreaming()) {
            $this->streamEvent('text_delta', ['delta' => 'async']);

            return $state->set('streamed', true);
        }

        return $state;
    }
}

class AsyncStoreOnlyIteratorNode extends AbstractNode
{
    protected ?string $name = 'sync-iterator';

    public function execute(StateContract $state): StateContract
    {
        if ($this->isStreaming()) {
            $this->streamEvent('text_delta', ['delta' => 'iterator']);

            return $state->set('path', 'streaming');
        }

        return $state->set('path', 'sync');
    }
}

function registerAsyncStoreOnlyGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('stream', AsyncStoreOnlyStreamNode::class)
        ->setEntryPoint('stream')
        ->addExitPoint('stream')
        ->build();

    AgenticGraph::register($name, $graph);
}

function registerSyncIteratorGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('alpha', AsyncStoreOnlyIteratorNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register($name, $graph);
}

function runAsyncJob(ExecuteGraphJob $job): void
{
    $job->handle(
        app(GraphExecutor::class),
        app(GraphRegistry::class)
    );
}

/**
 * @return array<int, StreamEvent>
 */
function storeEventsFor(string $streamId): array
{
    $store = app(StreamStoreInterface::class);

    return iterator_to_array($store->readFrom($streamId, 1, 100));
}

afterEach(function () {
    Mockery::close();
});

beforeEach(function () {
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    config()->set('agenticgraph.streaming.store.driver', 'database');
    app()->forgetInstance(StreamStoreInterface::class);
});

it('ASYNC+SSE writes to store via null transport sink without in-process SSE buffer', function () {
    Bus::fake();
    registerAsyncStoreOnlyGraph('async-sse-store');

    $handle = AgenticGraph::execute(
        'async-sse-store',
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::SSE)
    );

    $streamId = $handle->streamId();
    expect($streamId)->not->toBe('');

    $factory = app(StreamSinkFactory::class);
    $session = new StreamSession(
        streamId: $streamId,
        executionId: $handle->id(),
        options: new StreamOptions(delivery: 'sse'),
        startedAtUnix: time()
    );
    expect($factory->create($session, new StreamOptions(delivery: 'sse')))
        ->toBeInstanceOf(NullTransportSink::class);

    $job = null;
    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $dispatched) use (&$job): bool {
        $job = $dispatched;

        return true;
    });

    runAsyncJob($job);

    $events = storeEventsFor($streamId);
    $types = array_map(fn (StreamEvent $event) => $event->type(), $events);

    expect($types)->toContain('text_delta')
        ->and($types)->toContain(StreamEventEnvelope::TYPE_EXECUTION_TERMINAL);
});

it('ASYNC+BROADCAST broadcasts and writes to store', function () {
    registerAsyncStoreOnlyGraph('async-broadcast-store');

    $streamId = uniqid('stream_', true);
    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->atLeast()->once()->with(
        Mockery::on(fn ($channels): bool => is_array($channels) && $channels[0] === 'agenticgraph.stream.'.$streamId),
        Mockery::type('string'),
        Mockery::on(function ($payload): bool {
            return is_array($payload)
                && (array_key_exists('status', $payload)
                    || (isset($payload['stream_id'], $payload['execution_id'], $payload['seq'])
                        && ! array_key_exists('type', $payload)));
        })
    );
    app()->instance(Broadcaster::class, $broadcaster);

    $job = new ExecuteGraphJob(
        graphName: 'async-broadcast-store',
        executionId: uniqid('exec_', true),
        stateData: (new State)->toArray(),
        checkpointId: null,
        rootSpanId: uniqid('span_', true),
        executionOptions: new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::BROADCAST),
        streamId: $streamId,
        streamSeq: 0
    );

    runAsyncJob($job);

    $events = storeEventsFor($streamId);

    expect($events)->not->toBeEmpty()
        ->and($events[0]->streamId())->toBe($streamId);
});

it('ASYNC+NONE writes only via non-streaming path with no transport sink', function () {
    Bus::fake();
    registerAsyncStoreOnlyGraph('async-none-store');

    $handle = AgenticGraph::execute(
        'async-none-store',
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::NONE)
    );

    $job = null;
    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $dispatched) use (&$job): bool {
        $job = $dispatched;

        return true;
    });

    runAsyncJob($job);

    $streamId = $handle->streamId();
    if (is_string($streamId) && $streamId !== '') {
        expect(storeEventsFor($streamId))->toBeEmpty();
    } else {
        expect($streamId)->toBeIn([null, '']);
    }
});

it('SYNC+ITERATOR retains in-process iterator streaming', function () {
    registerSyncIteratorGraph('sync-iterator-intact');

    $handle = AgenticGraph::execute(
        'sync-iterator-intact',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR)
    );

    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
    $types = array_map(fn ($event) => $event->type(), $events);

    expect($types)->toContain('text_delta')
        ->and($handle->getFinalState()->get('path'))->toBe('streaming');
});

it('SYNC+SSE handle stream returns null after inline path removal', function () {
    registerSyncIteratorGraph('sync-sse-removed');

    $handle = AgenticGraph::execute(
        'sync-sse-removed',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::SSE)
    );

    expect($handle->stream()->hasStream())->toBeFalse();
});
