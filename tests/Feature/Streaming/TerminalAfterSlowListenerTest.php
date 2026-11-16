<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Exceptions\ExecutionEndedException;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamErrorHandler;
use Shahzad\AgenticGraph\Streaming\StreamMetrics;
use Shahzad\AgenticGraph\Streaming\StreamObserver;
use Shahzad\AgenticGraph\Streaming\WrappedStreamSink;

class SlowListenerOkNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        $this->streamEvent('text_delta', ['delta' => 'hi']);

        return $state->set('done', true);
    }
}

class SlowListenerFailingNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        $this->streamEvent('text_delta', ['delta' => 'hi']);

        throw new RuntimeException('node failed');
    }
}

/** @return list<string> stored event types of the graph's stream */
function slowListenerStored(string $name): array
{
    $streamId = DB::table('agenticgraph_executions')->where('graph_name', $name)->value('stream_id');

    return $streamId === null ? [] : array_map(
        fn (StreamEvent $event) => $event->type(),
        array_values(iterator_to_array(app(StreamStoreInterface::class)->readFrom($streamId, 1, 1000), false))
    );
}

/**
 * Runs one streamed node. The completion listener (a slow trace export) passes the 10 s heartbeat
 * interval and records the stored events it sees.
 *
 * @return array{stored: list<string>, seen_by_listener: list<string>, error: ?string}
 */
function slowListenerRun(string $name, string $node, string $listenOn): array
{
    $seen = [];
    Event::listen($listenOn, function () use ($name, &$seen) {
        $seen = slowListenerStored($name);
        test()->travel(11)->seconds();
    });
    AgenticGraph::register($name, GraphBuilder::create($name)->addNode('a', $node)->setEntryPoint('a')->addExitPoint('a')->build());

    $error = null;
    try {
        AgenticGraph::execute($name, new State(['topic' => 'x']), new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::BROADCAST,
        ));
    } catch (Throwable $e) {
        $error = $e::class.': '.$e->getMessage();
    }

    return ['stored' => slowListenerStored($name), 'seen_by_listener' => $seen, 'error' => $error];
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('broadcasting.default', 'null');
    app()->forgetInstance(StreamStoreInterface::class);
});

it('writes graph_complete and execution_terminal when a completion listener passes the heartbeat interval', function () {
    $run = slowListenerRun('slow-complete', SlowListenerOkNode::class, GraphExecutionCompleted::class);

    expect($run['error'])->toBeNull()
        ->and(array_slice($run['stored'], -2))->toBe(['graph_complete', 'execution_terminal'])
        ->and(DB::table('agenticgraph_executions')->where('graph_name', 'slow-complete')->value('status'))->toBe('completed');
});

it('writes the end events before the completion listeners run', function () {
    $run = slowListenerRun('slow-complete-order', SlowListenerOkNode::class, GraphExecutionCompleted::class);

    expect(array_slice($run['seen_by_listener'], -2))->toBe(['graph_complete', 'execution_terminal']);
});

it('writes graph_failed and execution_terminal when a failure listener passes the heartbeat interval', function () {
    $run = slowListenerRun('slow-failed', SlowListenerFailingNode::class, GraphExecutionFailed::class);

    expect($run['error'])->toBe('RuntimeException: node failed')
        ->and(array_slice($run['stored'], -2))->toBe(['graph_failed', 'execution_terminal'])
        ->and(array_slice($run['seen_by_listener'], -2))->toBe(['graph_failed', 'execution_terminal']);
});

it('emits the end events of an ended execution, and stops other events, when the heartbeat is due', function () {
    $repo = Mockery::mock(ExecutionRepository::class);
    $repo->shouldReceive('touch')->andReturnFalse(); // The worker's own completed() or failed() ended the row.
    app()->instance(ExecutionRepository::class, $repo);
    $make = fn (string $type) => new StreamEventImpl(
        's-hb', 'e-hb', null, null, null, $type, [], 1, null, new DateTimeImmutable
    );
    $wrap = fn ($sink) => new WrappedStreamSink(
        $sink, null, null, null, null,
        new StreamObserver(new StreamMetrics, 's-hb', 'e-hb', (float) time()),
        new StreamErrorHandler,
    );

    foreach (['graph_complete', 'graph_failed'] as $type) {
        $sink = new MemorySink;
        $wrap($sink)->emit($make($type));
        expect($sink->events()->count())->toBe(1);
    }

    expect(fn () => $wrap(new MemorySink)->emit($make('text_delta')))
        ->toThrow(ExecutionEndedException::class);
});
