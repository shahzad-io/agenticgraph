<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Http\Controllers\StreamController;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;

class ControllerTerminalCompleteNode extends AbstractNode implements \Shahzad\AgenticGraph\Nodes\Contracts\StreamableNodeInterface
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

class ControllerTerminalInterruptNode extends PrismNode
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

class ControllerTerminalFailingNode extends AbstractNode implements \Shahzad\AgenticGraph\Nodes\Contracts\StreamableNodeInterface
{
    public function execute(StateContract $state): StateContract
    {
        throw new RuntimeException('controller terminal failure');
    }

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        throw new RuntimeException('controller terminal failure');
    }
}

/**
 * @return array{content: string, elapsed: float}
 */
function runStreamControllerSse(string $streamId, int $maxDurationSeconds = 30): array
{
    config()->set('agenticgraph.streaming.sse.max_duration_seconds', $maxDurationSeconds);
    config()->set('agenticgraph.streaming.sse.poll_interval_ms', 50);
    config()->set('agenticgraph.streaming.sse.heartbeat_interval', 0);

    $store = app(StreamStoreInterface::class);
    $request = Request::create('/streams/'.$streamId, 'GET');
    $controller = new StreamController;
    $response = $controller->stream($streamId, $request, $store);

    $start = microtime(true);
    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();

    return [
        'content' => $content,
        'elapsed' => microtime(true) - $start,
    ];
}

function controllerStreamingOptions(): ExecutionOptions
{
    return new ExecutionOptions(
        mode: ExecutionMode::SYNC,
        streaming: StreamingMode::ITERATOR,
        interrupts: true
    );
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.checkpoint.enabled', true);
});

it('terminates store-backed sse promptly on interrupted execution_terminal', function () {
    $graph = GraphBuilder::create('controller-terminal-interrupt')
        ->addNode('approve', ControllerTerminalInterruptNode::class)
        ->setEntryPoint('approve')
        ->addExitPoint('approve')
        ->build();

    AgenticGraph::register('controller-terminal-interrupt', $graph);

    $handle = AgenticGraph::execute('controller-terminal-interrupt', new State, controllerStreamingOptions());
    $streamId = (string) $handle->streamId();

    $result = runStreamControllerSse($streamId, 30);

    expect($result['content'])->toContain('event: execution_terminal')
        ->and($result['content'])->toContain('"status":"interrupted"')
        ->and($result['content'])->toContain('final_state')
        ->and($result['elapsed'])->toBeLessThan(2.0);
});

it('terminates store-backed sse on completed execution_terminal with final_state', function () {
    $graph = GraphBuilder::create('controller-terminal-complete')
        ->addNode('alpha', ControllerTerminalCompleteNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register('controller-terminal-complete', $graph);

    $handle = AgenticGraph::execute('controller-terminal-complete', new State, controllerStreamingOptions());
    $streamId = (string) $handle->streamId();

    $result = runStreamControllerSse($streamId, 30);

    expect($result['content'])->toContain('event: execution_terminal')
        ->and($result['content'])->toContain('"status":"completed"')
        ->and($result['content'])->toContain('final_state')
        ->and($result['elapsed'])->toBeLessThan(2.0);
});

it('terminates store-backed sse on failed execution_terminal', function () {
    $graph = GraphBuilder::create('controller-terminal-failed')
        ->addNode('fail', ControllerTerminalFailingNode::class)
        ->setEntryPoint('fail')
        ->addExitPoint('fail')
        ->build();

    AgenticGraph::register('controller-terminal-failed', $graph);

    try {
        AgenticGraph::execute('controller-terminal-failed', new State, controllerStreamingOptions());
        expect(false)->toBeTrue('expected streaming execution to fail');
    } catch (RuntimeException) {
    }

    $row = DB::table('agenticgraph_executions')
        ->where('graph_name', 'controller-terminal-failed')
        ->orderByDesc('created_at')
        ->first();
    expect($row)->not->toBeNull();

    $result = runStreamControllerSse((string) $row->stream_id, 30);

    expect($result['content'])->toContain('event: execution_terminal')
        ->and($result['content'])->toContain('"status":"failed"')
        ->and($result['content'])->toContain('final_state')
        ->and($result['elapsed'])->toBeLessThan(2.0);
});

it('does not terminate on graph_complete without execution_terminal', function () {
    $store = app(StreamStoreInterface::class);
    $streamId = 'stream-legacy-complete-only';

    $store->append(new StreamEventImpl(
        streamId: $streamId,
        executionId: 'exec-legacy',
        nodeName: null,
        spanId: null,
        parentSpanId: null,
        type: 'graph_complete',
        payload: ['duration_ms' => 12.5],
        seq: 1,
        nodeSeq: null,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:01Z')
    ));

    $result = runStreamControllerSse($streamId, 1);

    expect($result['content'])->toContain('event: graph_complete')
        ->and($result['content'])->not->toContain('event: execution_terminal')
        ->and($result['elapsed'])->toBeGreaterThan(0.8);
});

it('terminates on seeded execution_terminal without waiting for max_duration', function () {
    $store = app(StreamStoreInterface::class);
    $streamId = 'stream-seeded-terminal';

    $store->append(new StreamEventImpl(
        streamId: $streamId,
        executionId: 'exec-seeded',
        nodeName: 'approve',
        spanId: null,
        parentSpanId: null,
        type: 'node_interrupt',
        payload: ['reason' => 'Needs approval'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:01Z')
    ));
    $store->append(new StreamEventImpl(
        streamId: $streamId,
        executionId: 'exec-seeded',
        nodeName: null,
        spanId: null,
        parentSpanId: null,
        type: StreamEventEnvelope::TYPE_EXECUTION_TERMINAL,
        payload: [
            'status' => 'interrupted',
            'final_state' => ['__agenticgraph_state' => ['data' => ['visited' => true], 'metadata' => []]],
        ],
        seq: 2,
        nodeSeq: null,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:02Z')
    ));

    $result = runStreamControllerSse($streamId, 30);

    expect($result['content'])->toContain('event: node_interrupt')
        ->and($result['content'])->toContain('event: execution_terminal')
        ->and($result['content'])->toContain('"status":"interrupted"')
        ->and($result['elapsed'])->toBeLessThan(2.0);
});
