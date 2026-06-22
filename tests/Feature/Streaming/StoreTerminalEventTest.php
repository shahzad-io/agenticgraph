<?php

use Illuminate\Support\Facades\DB;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionHandle;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Http\Controllers\StreamController;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;

class StoreTerminalCompleteNode extends AbstractNode implements \Shahzad\AgenticGraph\Nodes\Contracts\StreamableNodeInterface
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('done', true);
    }

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        return $state->set('done', true);
    }
}

class StoreTerminalInterruptNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('visited', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->has('approved');
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Needs approval';
    }

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        return $state->set('visited', true);
    }
}

class StoreTerminalFailingNode extends AbstractNode implements \Shahzad\AgenticGraph\Nodes\Contracts\StreamableNodeInterface
{
    public function execute(StateContract $state): StateContract
    {
        throw new RuntimeException('store terminal failure');
    }

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        throw new RuntimeException('store terminal failure');
    }
}

function streamingStoreOptions(): ExecutionOptions
{
    return new ExecutionOptions(
        mode: ExecutionMode::SYNC,
        streaming: StreamingMode::ITERATOR,
        interrupts: true
    );
}

/**
 * @return array<int, StreamEvent>
 */
function storedEventsForHandle(ExecutionHandle $handle): array
{
    $streamId = $handle->streamId();
    expect($streamId)->not->toBeNull()->not->toBe('');

    return iterator_to_array(app(StreamStoreInterface::class)->readFrom((string) $streamId, 1, 1000));
}

/**
 * @param  array<int, StreamEvent>  $events
 * @return array<int, StreamEvent>
 */
function executionTerminalEvents(array $events): array
{
    return array_values(array_filter(
        $events,
        fn (StreamEvent $event) => $event->type() === StreamEventEnvelope::TYPE_EXECUTION_TERMINAL
    ));
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.checkpoint.enabled', true);
});

it('writes exactly one completed terminal event with embedded final_state', function () {
    $graph = GraphBuilder::create('store-terminal-complete')
        ->addNode('alpha', StoreTerminalCompleteNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register('store-terminal-complete', $graph);

    $handle = AgenticGraph::execute('store-terminal-complete', new State, streamingStoreOptions());
    $events = storedEventsForHandle($handle);
    $terminals = executionTerminalEvents($events);

    expect($terminals)->toHaveCount(1)
        ->and($terminals[0]->payload()['status'])->toBe('completed')
        ->and($terminals[0]->payload()['final_state'])->toBeArray()
        ->and($terminals[0]->payload()['final_state']['__agenticgraph_state']['data']['done'] ?? null)->toBeTrue();
});

it('writes exactly one interrupted terminal event with honest status and final_state', function () {
    $graph = GraphBuilder::create('store-terminal-interrupt')
        ->addNode('approve', StoreTerminalInterruptNode::class)
        ->setEntryPoint('approve')
        ->addExitPoint('approve')
        ->build();

    AgenticGraph::register('store-terminal-interrupt', $graph);

    $handle = AgenticGraph::execute('store-terminal-interrupt', new State, streamingStoreOptions());
    $events = storedEventsForHandle($handle);
    $terminals = executionTerminalEvents($events);

    expect($handle->isInterrupted())->toBeTrue()
        ->and($terminals)->toHaveCount(1)
        ->and($terminals[0]->payload()['status'])->toBe('interrupted')
        ->and($terminals[0]->payload()['status'])->not->toBe('completed')
        ->and($terminals[0]->payload()['final_state'])->toBeArray();
});

it('writes exactly one failed terminal event for streaming failures', function () {
    $graph = GraphBuilder::create('store-terminal-failed')
        ->addNode('fail', StoreTerminalFailingNode::class)
        ->setEntryPoint('fail')
        ->addExitPoint('fail')
        ->build();

    AgenticGraph::register('store-terminal-failed', $graph);

    try {
        AgenticGraph::execute('store-terminal-failed', new State, streamingStoreOptions());
        expect(false)->toBeTrue('expected streaming execution to fail');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('store terminal failure');
    }

    $row = DB::table('agenticgraph_executions')
        ->where('graph_name', 'store-terminal-failed')
        ->orderByDesc('created_at')
        ->first();
    expect($row)->not->toBeNull();
    $streamId = $row->stream_id;

    $events = iterator_to_array(app(StreamStoreInterface::class)->readFrom((string) $streamId, 1, 1000));
    $terminals = executionTerminalEvents($events);

    expect($terminals)->toHaveCount(1)
        ->and($terminals[0]->payload()['status'])->toBe('failed')
        ->and($terminals[0]->payload()['final_state'])->toBeArray();
});

it('stream controller serializes unified envelope without duplicate type field', function () {
    $event = new StreamEventImpl(
        streamId: 'stream-ctrl',
        executionId: 'exec-ctrl',
        nodeName: 'node-a',
        spanId: 'span-1',
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'x'],
        seq: 3,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:03Z')
    );

    $controller = new StreamController;
    $method = new ReflectionMethod($controller, 'formatEvent');
    $method->setAccessible(true);
    $content = $method->invoke($controller, $event);

    expect($content)->toContain('event: text_delta')
        ->and($content)->toContain('"stream_id":"stream-ctrl"')
        ->and($content)->not->toContain('"type":"text_delta"');
});

it('lifecycle emitter preserves payload.stream nesting in stored events', function () {
    $graph = GraphBuilder::create('store-terminal-lifecycle')
        ->addNode('alpha', StoreTerminalCompleteNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register('store-terminal-lifecycle', $graph);

    $handle = AgenticGraph::execute('store-terminal-lifecycle', new State, streamingStoreOptions());
    $events = storedEventsForHandle($handle);
    $starts = array_values(array_filter($events, fn ($e) => $e->type() === 'graph_start'));

    expect($starts)->not->toBeEmpty()
        ->and($starts[0]->payload()['stream'] ?? null)->toMatchArray([
            'seq' => $starts[0]->seq(),
            'node_seq' => null,
            'event_type' => 'graph_start',
        ]);
});
