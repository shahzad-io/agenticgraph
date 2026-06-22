<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Prism\Prism\ValueObjects\ToolCall;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Http\Controllers\StreamController;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\Contracts\StreamableNodeInterface;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\Store\DatabaseStreamStore;
use Shahzad\AgenticGraph\Streaming\Store\RedisStreamStore;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamStatus;
use Shahzad\AgenticGraph\Tracing\TraceContext;

it('creates emits closes and verifies in store', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    $store = app(StreamStoreInterface::class);
    $streamId = (string) Str::uuid();
    $sink = new MemorySink;

    $events = [
        new StreamEventImpl(
            streamId: $streamId,
            executionId: 'exec-1',
            nodeName: 'node-a',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => 'a'],
            seq: 1,
            nodeSeq: 1,
            occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
        ),
        new StreamEventImpl(
            streamId: $streamId,
            executionId: 'exec-1',
            nodeName: 'node-a',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => 'b'],
            seq: 2,
            nodeSeq: 2,
            occurredAt: new DateTimeImmutable('2024-01-01T00:00:01Z')
        ),
    ];

    foreach ($events as $event) {
        $sink->emit($event);
        $store->append($event);
    }
    $sink->close(StreamStatus::SUCCESS, ['done' => true]);

    $stored = iterator_to_array($store->readFrom($streamId, 1, 10));

    expect($stored)->toHaveCount(2)
        ->and($sink->status()?->value)->toBe('success')
        ->and($sink->meta())->toBe(['done' => true])
        ->and($stored[1]->payload())->toBe(['delta' => 'b']);
});

it('replays from cursor', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    $store = app(StreamStoreInterface::class);
    $streamId = (string) Str::uuid();

    foreach ([1, 2, 3] as $seq) {
        $store->append(new StreamEventImpl(
            streamId: $streamId,
            executionId: 'exec-1',
            nodeName: 'node-a',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => (string) $seq],
            seq: $seq,
            nodeSeq: $seq,
            occurredAt: new DateTimeImmutable('2024-01-01T00:00:0'.$seq.'Z')
        ));
    }

    $events = iterator_to_array($store->readFrom($streamId, 2, 10));
    $seqs = array_map(fn ($event) => $event->seq(), $events);

    expect($seqs)->toBe([2, 3]);
});

it('replays store events as sse wire format on reconnection', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    $store = app(StreamStoreInterface::class);
    $streamId = (string) Str::uuid();

    foreach ([1, 2, 3] as $seq) {
        $store->append(new StreamEventImpl(
            streamId: $streamId,
            executionId: 'exec-1',
            nodeName: 'node-a',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => (string) $seq],
            seq: $seq,
            nodeSeq: $seq,
            occurredAt: new DateTimeImmutable('2024-01-01T00:00:0'.$seq.'Z')
        ));
    }

    $content = '';
    foreach ($store->readFrom($streamId, 2, 10) as $event) {
        $content .= StreamEventEnvelope::fromStreamEvent($event)->formatSse();
    }

    expect($content)->toContain('id: 2')
        ->and($content)->toContain('id: 3')
        ->and($content)->toContain('event: text_delta');
});

it('switches stream store drivers', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    app()->forgetInstance(StreamStoreInterface::class);
    $databaseStore = app(StreamStoreInterface::class);

    expect($databaseStore)->toBeInstanceOf(DatabaseStreamStore::class);

    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis is not available');
    }

    config()->set('agenticgraph.streaming.store.driver', 'redis');
    app()->forgetInstance(StreamStoreInterface::class);
    $redisStore = app(StreamStoreInterface::class);

    expect($redisStore)->toBeInstanceOf(RedisStreamStore::class);
});

class StoreWritingSink implements StreamSinkInterface
{
    public ?string $streamId = null;

    public function __construct(private StreamStoreInterface $store) {}

    public function emit(StreamEvent $event): void
    {
        $this->streamId ??= $event->streamId();
        $this->store->append($event);
    }

    public function close(StreamStatus $status, mixed $meta = null): void {}
}

class IntegrationFakeStreamingRequest extends PendingTextRequest
{
    public function __construct(private array $events) {}

    public function asStream(): Generator
    {
        foreach ($this->events as $event) {
            yield $event;
        }
    }
}

class IntegrationStreamingNode extends PrismNode
{
    public function __construct(private array $events) {}

    public function execute(StateContract $state): StateContract
    {
        return $state->set('text', 'Hello world')->set('tool_calls', [
            [
                'tool_id' => 'tool-1',
                'tool_name' => 'search',
                'arguments' => ['query' => 'a'],
                'message_id' => 'msg-1',
            ],
            [
                'tool_id' => 'tool-2',
                'tool_name' => 'lookup',
                'arguments' => ['query' => 'b'],
                'message_id' => 'msg-1',
            ],
        ]);
    }

    protected function buildStreamingRequest(StateContract $state): PendingTextRequest
    {
        return new IntegrationFakeStreamingRequest($this->events);
    }
}

it('streams prism node events into the stream store', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    $store = app(StreamStoreInterface::class);

    $events = [
        new TextStartEvent('evt-1', 100, 'msg-1'),
        new TextDeltaEvent('evt-2', 101, 'Hello ', 'msg-1'),
        new TextDeltaEvent('evt-3', 102, 'world', 'msg-1'),
        new TextCompleteEvent('evt-4', 103, 'msg-1'),
        new \Prism\Prism\Streaming\Events\ToolCallEvent(
            'evt-5',
            104,
            new ToolCall('tool-1', 'search', ['query' => 'a']),
            'msg-1'
        ),
        new \Prism\Prism\Streaming\Events\ToolCallEvent(
            'evt-6',
            105,
            new ToolCall('tool-2', 'lookup', ['query' => 'b']),
            'msg-1'
        ),
    ];

    $node = new IntegrationStreamingNode($events);
    $sink = new StoreWritingSink($store);
    $state = new State(['prompt' => 'Hello']);

    $streamed = $node->executeStreaming($state, $sink);
    $nonStreamed = $node->execute($state);

    expect($streamed->get('text'))->toBe($nonStreamed->get('text'))
        ->and($streamed->get('tool_calls'))->toBe($nonStreamed->get('tool_calls'));

    $streamId = $sink->streamId;
    $stored = iterator_to_array($store->readFrom($streamId, 1, 20));

    expect($stored)->toHaveCount(6);
});

class StreamableTestNode extends AbstractNode implements StreamableNodeInterface
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('path', 'sync');
    }

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        $session = app(StreamContext::class)->session();
        $trace = app(TraceContext::class);
        $session->seq++;

        $sink->emit(new StreamEventImpl(
            streamId: $session->streamId,
            executionId: $session->executionId,
            nodeName: 'alpha',
            spanId: $trace->getSpanId(),
            parentSpanId: $trace->getParentSpanId(),
            type: 'text_delta',
            payload: ['delta' => 'hi'],
            seq: $session->seq,
            nodeSeq: 1,
            occurredAt: new DateTimeImmutable
        ));

        return $state->set('path', 'streaming');
    }
}

class NonStreamableTestNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('beta', true);
    }
}

class StreamContextCapturingNode extends AbstractNode implements StreamableNodeInterface
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        $session = app(StreamContext::class)->session();

        return $state->set('stream_id', $session->streamId);
    }
}

class ThrowingStreamableNode extends AbstractNode implements StreamableNodeInterface
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        throw new RuntimeException('streaming node failed');
    }
}

class SilentStreamNode extends AbstractNode implements StreamableNodeInterface
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('silent', true);
    }

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        return $state->set('silent', true);
    }
}

class RapidFireStreamNode extends AbstractNode implements StreamableNodeInterface
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('rapid', true);
    }

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        $session = app(StreamContext::class)->session();
        $trace = app(TraceContext::class);

        for ($i = 0; $i < 1000; $i++) {
            $session->seq++;
            $sink->emit(new StreamEventImpl(
                streamId: $session->streamId,
                executionId: $session->executionId,
                nodeName: 'rapid',
                spanId: $trace->getSpanId(),
                parentSpanId: $trace->getParentSpanId(),
                type: 'text_delta',
                payload: ['delta' => (string) $i],
                seq: $session->seq,
                nodeSeq: $i + 1,
                occurredAt: new DateTimeImmutable
            ));
        }

        return $state->set('rapid', true);
    }
}

class ParallelStreamNodeA extends AbstractNode implements StreamableNodeInterface
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('a', true);
    }

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        $session = app(StreamContext::class)->session();
        $session->seq++;
        $sink->emit(new StreamEventImpl(
            streamId: $session->streamId,
            executionId: $session->executionId,
            nodeName: 'a',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => 'a'],
            seq: $session->seq,
            nodeSeq: 1,
            occurredAt: new DateTimeImmutable
        ));

        return $state->set('a', true);
    }
}

class ParallelStreamNodeB extends AbstractNode implements StreamableNodeInterface
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('b', true);
    }

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        $session = app(StreamContext::class)->session();
        $session->seq++;
        $sink->emit(new StreamEventImpl(
            streamId: $session->streamId,
            executionId: $session->executionId,
            nodeName: 'b',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => 'b'],
            seq: $session->seq,
            nodeSeq: 1,
            occurredAt: new DateTimeImmutable
        ));

        return $state->set('b', true);
    }
}

class MultiInterruptNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('done', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return (int) $state->get('interrupt_count', 0) < 2;
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Needs approval';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        $count = (int) $state->get('interrupt_count', 0);

        return $state->set('interrupt_count', $count + 1);
    }

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        return $state->set('done', true);
    }
}

class StreamingApprovalNode extends PrismNode
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

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        return $state->set('processed', true);
    }
}

it('streams workflow lifecycle events and uses streaming execution', function () {
    config()->set('agenticgraph.tracing.enabled', true);

    $graph = GraphBuilder::create('streaming-workflow')
        ->addNode('alpha', StreamableTestNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register('streaming-workflow', $graph);

    $handle = AgenticGraph::execute('streaming-workflow', new State, streamingOptions(StreamingMode::ITERATOR));

    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
    $types = array_map(fn ($event) => $event->type(), $events);

    expect($types)->toBe(['graph_start', 'node_start', 'text_delta', 'node_complete', 'graph_complete'])
        ->and($handle->getFinalState()->get('path'))->toBe('streaming')
        ->and($handle->streamId())->not->toBe('')
        ->and($handle->id())->not->toBe('')
        ->and($events[0]->spanId())->not->toBeNull()
        ->and($events[1]->spanId())->not->toBeNull();

    expect(app()->bound(StreamContext::class))->toBeFalse();
});

it('handles rapid fire stream events', function () {
    $graph = GraphBuilder::create('rapid-fire')
        ->addNode('rapid', RapidFireStreamNode::class)
        ->setEntryPoint('rapid')
        ->addExitPoint('rapid')
        ->build();

    AgenticGraph::register('rapid-fire', $graph);

    $handle = AgenticGraph::execute('rapid-fire', new State, streamingOptions(StreamingMode::ITERATOR));

    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
    $rapid = array_values(array_filter($events, fn ($event) => $event->type() === 'text_delta'));

    expect($rapid)->toHaveCount(1000)
        ->and($handle->getFinalState()->get('rapid'))->toBeTrue();
});

it('handles empty streams without data events', function () {
    $graph = GraphBuilder::create('silent-stream')
        ->addNode('silent', SilentStreamNode::class)
        ->setEntryPoint('silent')
        ->addExitPoint('silent')
        ->build();

    AgenticGraph::register('silent-stream', $graph);

    $handle = AgenticGraph::execute('silent-stream', new State, streamingOptions(StreamingMode::ITERATOR));

    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
    $types = array_map(fn ($event) => $event->type(), $events);

    expect($types)->toBe(['graph_start', 'node_start', 'node_complete', 'graph_complete'])
        ->and($handle->getFinalState()->get('silent'))->toBeTrue();
});

it('streams parallel nodes with interleaved events', function () {
    $graph = GraphBuilder::create('parallel-stream')
        ->addNode('start', NonStreamableTestNode::class)
        ->addNode('a', ParallelStreamNodeA::class)
        ->addNode('b', ParallelStreamNodeB::class)
        ->addNode('end', NonStreamableTestNode::class)
        ->addConditionalEdge('start', new class implements RouterInterface
        {
            public function route(StateContract $state): string|array
            {
                return ['a', 'b'];
            }

            public function getAvailableRoutes(): array
            {
                return ['a', 'b'];
            }

            public function getDefaultRoute(): ?string
            {
                return null;
            }

            public function validateRoute(string $route): bool
            {
                return in_array($route, ['a', 'b'], true);
            }
        })
        ->addJoin(['a', 'b'], 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();

    AgenticGraph::register('parallel-stream', $graph);

    $handle = AgenticGraph::execute('parallel-stream', new State, streamingOptions(StreamingMode::ITERATOR));

    iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
    $finalState = $handle->getFinalState();

    expect($finalState->get('a'))->toBeTrue()
        ->and($finalState->get('b'))->toBeTrue();
});

it('handles multiple interrupts in sequence', function () {
    $graph = GraphBuilder::create('multi-interrupt')
        ->addNode('approve', MultiInterruptNode::class)
        ->setEntryPoint('approve')
        ->addExitPoint('approve')
        ->build();

    AgenticGraph::register('multi-interrupt', $graph);

    $handle = AgenticGraph::execute('multi-interrupt', new State, streamingOptions(StreamingMode::ITERATOR));
    $checkpointId = $handle->getFinalState()->getInterruptCheckpointId();

    $resumeHandle = $handle->resume(['approved' => true]);
    $resumeCheckpointId = $resumeHandle->getFinalState()->getInterruptCheckpointId();

    $resumeEvents = iterator_to_array($resumeHandle->stream()->inline ?? new ArrayIterator([]));
    $resumeTypes = array_map(fn ($event) => $event->type(), $resumeEvents);

    expect($resumeTypes)->toContain('node_interrupt');

    $finalHandle = $resumeHandle;
    for ($i = 0; $i < 3; $i++) {
        if (! $finalHandle->getFinalState()->hasInterrupt()) {
            break;
        }
        $finalHandle = $finalHandle->resume(['approved' => true]);
    }

    expect($finalHandle->getFinalState()->hasInterrupt())->toBeFalse()
        ->and($finalHandle->getFinalState()->get('done'))->toBeTrue();
});

it('resumes without stream store enabled', function () {
    $graph = GraphBuilder::create('resume-no-store')
        ->addNode('approve', StreamingApprovalNode::class)
        ->setEntryPoint('approve')
        ->addExitPoint('approve')
        ->build();

    AgenticGraph::register('resume-no-store', $graph);

    $handle = AgenticGraph::execute('resume-no-store', new State(['prompt' => 'approve']), streamingOptions(StreamingMode::ITERATOR));
    $checkpointId = $handle->getFinalState()->getInterruptCheckpointId();

    $resumeHandle = $handle->resume(['approved' => true]);
    $events = iterator_to_array($resumeHandle->stream()->inline ?? new ArrayIterator([]));
    $resumeEvent = null;
    foreach ($events as $event) {
        if ($event->type() === 'stream_resume') {
            $resumeEvent = $event;
            break;
        }
    }

    expect($resumeEvent)->not->toBeNull()
        ->and($resumeHandle->getFinalState()->get('processed'))->toBeTrue();
});

it('replays from Last-Event-ID header', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.streaming.sse.max_duration_seconds', 2);
    $store = app(StreamStoreInterface::class);
    $streamId = (string) Str::uuid();

    foreach ([1, 2, 3] as $seq) {
        $store->append(new StreamEventImpl(
            streamId: $streamId,
            executionId: 'exec-1',
            nodeName: 'node-a',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => (string) $seq],
            seq: $seq,
            nodeSeq: $seq,
            occurredAt: new DateTimeImmutable('2024-01-01T00:00:0'.$seq.'Z')
        ));
    }

    $request = Request::create('/streams/'.$streamId, 'GET');
    $request->headers->set('Last-Event-ID', '1');

    $controller = new StreamController;
    $response = $controller->stream($streamId, $request, $store);

    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();

    expect($content)->toContain('id: 2')
        ->and($content)->toContain('id: 3')
        ->and($content)->toContain('event: text_delta');
});

it('replays from cursor query', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.streaming.sse.max_duration_seconds', 2);
    $store = app(StreamStoreInterface::class);
    $streamId = (string) Str::uuid();

    foreach ([1, 2, 3] as $seq) {
        $store->append(new StreamEventImpl(
            streamId: $streamId,
            executionId: 'exec-1',
            nodeName: 'node-a',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => (string) $seq],
            seq: $seq,
            nodeSeq: $seq,
            occurredAt: new DateTimeImmutable('2024-01-01T00:00:0'.$seq.'Z')
        ));
    }

    $request = Request::create('/streams/'.$streamId, 'GET', ['cursor' => 2]);
    $controller = new StreamController;
    $response = $controller->stream($streamId, $request, $store);

    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();

    expect($content)->toContain('id: 3')
        ->and($content)->toContain('event: text_delta');
});

it('emits stream replay unavailable when cursor missing', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.streaming.sse.max_duration_seconds', 2);
    $store = app(StreamStoreInterface::class);
    $streamId = (string) Str::uuid();

    $request = Request::create('/streams/'.$streamId, 'GET', ['cursor' => 10]);
    $controller = new StreamController;
    $response = $controller->stream($streamId, $request, $store);

    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();

    expect($content)->toContain('event: stream_replay_unavailable');
});

it('includes heartbeat events in stream controller response', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.streaming.sse.heartbeat_interval', 1);
    config()->set('agenticgraph.streaming.sse.max_duration_seconds', 2);
    $store = app(StreamStoreInterface::class);
    $streamId = (string) Str::uuid();

    $request = Request::create('/streams/'.$streamId, 'GET');
    $controller = new StreamController;
    $response = $controller->stream($streamId, $request, $store);

    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();

    expect($content)->toContain('event: stream_heartbeat');
});

it('handles broadcast channel unavailable', function () {
    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->andThrow(new RuntimeException('channel unavailable'));
    $sink = new BroadcastSink($broadcaster, 'stream-1');
    $event = new StreamEventImpl(
        streamId: 'stream-1',
        executionId: 'exec-1',
        nodeName: 'node-a',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'x'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
    );
    $caught = null;

    try {
        $sink->emit($event);
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getMessage())->toBe('channel unavailable');
});

it('links parent span ids for node lifecycle events', function () {
    config()->set('agenticgraph.tracing.enabled', true);

    $graph = GraphBuilder::create('span-link-workflow')
        ->addNode('alpha', StreamableTestNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register('span-link-workflow', $graph);

    $handle = AgenticGraph::execute('span-link-workflow', new State, streamingOptions(StreamingMode::ITERATOR));

    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
    $byType = [];
    foreach ($events as $event) {
        $byType[$event->type()][] = $event;
    }

    $graphStart = $byType['graph_start'][0] ?? null;
    $nodeStart = $byType['node_start'][0] ?? null;
    $nodeComplete = $byType['node_complete'][0] ?? null;

    expect($graphStart)->not->toBeNull()
        ->and($nodeStart)->not->toBeNull()
        ->and($nodeComplete)->not->toBeNull()
        ->and($graphStart->parentSpanId())->toBeNull()
        ->and($nodeStart->parentSpanId())->toBe($graphStart->spanId())
        ->and($nodeComplete->parentSpanId())->toBe($graphStart->spanId());
});

it('creates stream context per execution', function () {
    $graph = GraphBuilder::create('stream-context')
        ->addNode('alpha', StreamContextCapturingNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register('stream-context', $graph);

    $handle = AgenticGraph::execute('stream-context', new State, streamingOptions(StreamingMode::ITERATOR));

    expect($handle->getFinalState()->get('stream_id'))->toBe($handle->streamId())
        ->and(app()->bound(StreamContext::class))->toBeFalse();

    $second = AgenticGraph::execute('stream-context', new State, streamingOptions(StreamingMode::ITERATOR));

    expect($second->streamId())->not->toBe($handle->streamId())
        ->and($second->getFinalState()->get('stream_id'))->toBe($second->streamId())
        ->and(app()->bound(StreamContext::class))->toBeFalse();
});

it('clears stream context after streaming failure', function () {
    $graph = GraphBuilder::create('stream-failure')
        ->addNode('alpha', ThrowingStreamableNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register('stream-failure', $graph);

    try {
        AgenticGraph::execute('stream-failure', new State, streamingOptions(StreamingMode::ITERATOR));
    } catch (RuntimeException $e) {
    }

    expect(app()->bound(StreamContext::class))->toBeFalse();
});

it('does not leak stream context across many executions', function () {
    $graph = GraphBuilder::create('stream-context-loop')
        ->addNode('alpha', StreamContextCapturingNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register('stream-context-loop', $graph);

    $streamIds = [];

    for ($i = 0; $i < 120; $i++) {
        $handle = AgenticGraph::execute('stream-context-loop', new State, streamingOptions(StreamingMode::ITERATOR));
        $streamIds[] = $handle->streamId();
        expect(app()->bound(StreamContext::class))->toBeFalse();
    }

    expect(count(array_unique($streamIds)))->toBe(count($streamIds));
});

it('streams multi-node workflow in order and preserves final state', function () {
    $graph = GraphBuilder::create('multi-streaming')
        ->addNode('alpha', StreamableTestNode::class)
        ->addNode('beta', NonStreamableTestNode::class)
        ->addEdge('alpha', 'beta')
        ->setEntryPoint('alpha')
        ->addExitPoint('beta')
        ->build();

    AgenticGraph::register('multi-streaming', $graph);

    $handle = AgenticGraph::execute('multi-streaming', new State, streamingOptions(StreamingMode::ITERATOR));

    $types = array_map(fn ($event) => $event->type(), iterator_to_array($handle->stream()->inline ?? new ArrayIterator([])));

    expect($types)->toBe([
        'graph_start',
        'node_start',
        'text_delta',
        'node_complete',
        'node_start',
        'node_complete',
        'graph_complete',
    ])->and($handle->getFinalState()->get('beta'))->toBeTrue();
});

it('emits stream resume event with preserved stream id', function () {
    $graph = GraphBuilder::create('resume-stream')
        ->addNode('approve', StreamingApprovalNode::class)
        ->setEntryPoint('approve')
        ->addExitPoint('approve')
        ->build();

    AgenticGraph::register('resume-stream', $graph);

    $handle = AgenticGraph::execute('resume-stream', new State(['prompt' => 'approve']), streamingOptions(StreamingMode::ITERATOR));

    $initialState = $handle->getFinalState();
    $checkpointId = $initialState->getInterruptCheckpointId();

    expect($initialState->hasInterrupt())->toBeTrue()
        ->and($checkpointId)->not->toBeNull();
    $storage = app(StorageInterface::class);
    $checkpoint = $storage->load($checkpointId);

    expect($checkpoint)->not->toBeNull()
        ->and($checkpoint->metadata['stream_id'] ?? null)->toBe($handle->streamId())
        ->and($checkpoint->metadata['last_seq'] ?? null)->toBeInt()
        ->and($checkpoint->metadata['stream_cursor'] ?? null)->toBeInt();

    $resumeHandle = $handle->resume([
        'approved' => true,
        'comments' => 'ok',
    ]);

    $resumeEvents = iterator_to_array($resumeHandle->stream()->inline ?? new ArrayIterator([]));
    $resumeTypes = array_map(fn ($event) => $event->type(), $resumeEvents);
    $resumeEvent = null;

    foreach ($resumeEvents as $event) {
        if ($event->type() === 'stream_resume') {
            $resumeEvent = $event;
            break;
        }
    }

    expect(in_array('stream_resume', $resumeTypes, true))->toBeTrue()
        ->and($resumeEvent)->not->toBeNull()
        ->and($resumeEvent->payload())->toMatchArray(['checkpoint_id' => $checkpointId])
        ->and($resumeHandle->streamId())->toBe($handle->streamId())
        ->and($resumeHandle->getFinalState()->get('processed'))->toBeTrue();

    $resumePayload = $resumeEvent?->payload();
    expect($resumePayload['checkpoint_id'] ?? null)->toBe($checkpointId)
        ->and($resumePayload['from_seq'] ?? null)->toBe($checkpoint->metadata['last_seq'] ?? null)
        ->and($resumePayload['resumed_at_seq'] ?? null)->toBe(($checkpoint->metadata['last_seq'] ?? 0) + 1);
});

it('interrupt emits node_interrupt and closes stream', function () {
    $graph = GraphBuilder::create('interrupt-stream')
        ->addNode('approve', StreamingApprovalNode::class)
        ->setEntryPoint('approve')
        ->addExitPoint('approve')
        ->build();

    AgenticGraph::register('interrupt-stream', $graph);

    $handle = AgenticGraph::execute('interrupt-stream', new State(['prompt' => 'approve']), streamingOptions(StreamingMode::ITERATOR));

    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
    $types = array_map(fn ($event) => $event->type(), $events);

    expect($types)->toContain('node_interrupt');
    expect($handle->getFinalState()->getInterruptCheckpointId())->not->toBeNull();
});

it('includes gap events when resuming with resumeFromSeq', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');

    $graph = GraphBuilder::create('resume-gap')
        ->addNode('approve', StreamingApprovalNode::class)
        ->setEntryPoint('approve')
        ->addExitPoint('approve')
        ->build();

    AgenticGraph::register('resume-gap', $graph);

    $handle = AgenticGraph::execute('resume-gap', new State(['prompt' => 'approve']), streamingOptions(StreamingMode::ITERATOR));

    $checkpointId = $handle->getFinalState()->getInterruptCheckpointId();
    $resumeHandle = $handle->resume(['approved' => true]);
    $resumeEvents = iterator_to_array($resumeHandle->stream()->inline ?? new ArrayIterator([]));

    $resumeEvent = null;
    foreach ($resumeEvents as $event) {
        if ($event->type() === 'stream_resume') {
            $resumeEvent = $event;
            break;
        }
    }

    expect($resumeEvent)->not->toBeNull()
        ->and($resumeHandle->getFinalState()->get('processed'))->toBeTrue();
});

it('keeps non-streaming execution unchanged', function () {
    $graph = GraphBuilder::create('sync-only')
        ->addNode('alpha', NonStreamableTestNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register('sync-only', $graph);

    $handle = AgenticGraph::execute('sync-only', new State, new ExecutionOptions);

    expect($handle->getFinalState()->get('beta'))->toBeTrue();
});

it('applies node and event filters for streaming', function () {
    $graph = GraphBuilder::create('filtered-streaming')
        ->addNode('alpha', StreamableTestNode::class)
        ->addNode('beta', NonStreamableTestNode::class)
        ->addEdge('alpha', 'beta')
        ->setEntryPoint('alpha')
        ->addExitPoint('beta')
        ->build();

    AgenticGraph::register('filtered-streaming', $graph);

    $handle = AgenticGraph::execute('filtered-streaming', new State, streamingOptions(StreamingMode::ITERATOR));
    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
    $types = array_map(fn ($event) => $event->type(), $events);
    expect($types)->toContain('graph_complete')
        ->and($handle->getFinalState()->get('beta'))->toBeTrue();
});

it('does not return inline sse stream from sync stream handle', function () {
    $graph = GraphBuilder::create('sse-streaming')
        ->addNode('alpha', StreamableTestNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register('sse-streaming', $graph);

    $handle = AgenticGraph::execute('sse-streaming', new State, streamingOptions(StreamingMode::SSE));

    expect($handle->stream()->hasStream())->toBeFalse();
});

it('iterates over stream handle directly', function () {
    $graph = GraphBuilder::create('iterable-stream')
        ->addNode('alpha', StreamableTestNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register('iterable-stream', $graph);

    $handle = AgenticGraph::execute('iterable-stream', new State, streamingOptions(StreamingMode::ITERATOR));

    $types = [];
    foreach ($handle->stream()->inline ?? [] as $event) {
        $types[] = $event->type();
    }

    expect($types)->toBe(['graph_start', 'node_start', 'text_delta', 'node_complete', 'graph_complete'])
        ->and($handle->getFinalState()->get('path'))->toBe('streaming');
});

it('broadcasts stream events via facade', function () {
    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->atLeast()->once()->with(
        Mockery::on(function ($channels): bool {
            return is_array($channels)
                && isset($channels[0])
                && is_string($channels[0])
                && str_starts_with($channels[0], 'agenticgraph.stream.');
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

    $graph = GraphBuilder::create('broadcast-stream')
        ->addNode('alpha', StreamableTestNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register('broadcast-stream', $graph);

    $handle = AgenticGraph::execute('broadcast-stream', new State, streamingOptions(StreamingMode::BROADCAST));

    expect($handle->streamId())->not->toBe('');
});

function streamingOptions(StreamingMode $mode, bool $interrupts = true): ExecutionOptions
{
    return new ExecutionOptions(
        mode: ExecutionMode::SYNC,
        streaming: $mode,
        interrupts: $interrupts
    );
}
