<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Http\Controllers\StreamController;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Sinks\NullTransportSink;
use Shahzad\AgenticGraph\Streaming\StreamDescriptor;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Streaming\StreamSinkFactory;

class ConsumerPathStreamNode extends AbstractNode
{
    protected ?string $name = 'stream';

    public function execute(StateContract $state): StateContract
    {
        if ($this->isStreaming()) {
            foreach (['first', 'second'] as $delta) {
                $this->streamEvent('text_delta', ['delta' => $delta]);
            }

            return $state->set('streamed', true);
        }

        return $state;
    }
}

class ConsumerPathInterruptNode extends PrismNode
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
        return 'Consumer path interrupt';
    }
}

function registerConsumerPathGraph(string $name, string $nodeClass): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('stream', $nodeClass)
        ->setEntryPoint('stream')
        ->addExitPoint('stream')
        ->build();

    AgenticGraph::register($name, $graph);
}

function runConsumerPathExecuteGraphJob(): ExecuteGraphJob
{
    $job = null;

    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $dispatched) use (&$job): bool {
        $job = $dispatched;

        return true;
    });

    expect($job)->toBeInstanceOf(ExecuteGraphJob::class);

    $job->handle(app(GraphExecutor::class), app(GraphRegistry::class));

    return $job;
}

/**
 * @return array<int, StreamEvent>
 */
function storedStreamEvents(string $streamId): array
{
    return iterator_to_array(app(StreamStoreInterface::class)->readFrom($streamId, 1, 1000));
}

/**
 * Invokes StreamController directly for a streamId (same action the named route serves).
 *
 * @return array{content: string, elapsed: float}
 */
function invokeStreamControllerForStreamId(string $streamId, int $maxDurationSeconds = 30): array
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

/**
 * @param  array<int, StreamEvent>  $events
 * @return array<int, StreamEvent>
 */
function executionTerminalEventsFrom(array $events): array
{
    return array_values(array_filter(
        $events,
        fn (StreamEvent $event) => $event->type() === StreamEventEnvelope::TYPE_EXECUTION_TERMINAL
    ));
}

beforeEach(function () {
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.streaming.routes.enabled', true);
    config()->set('agenticgraph.streaming.routes.prefix', 'agentic-graph/streams');
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
    app()->forgetInstance(StreamStoreInterface::class);
});

it('replayFrom cursor 0 yields store events through execution_terminal', function () {
    Bus::fake();
    registerConsumerPathGraph('consumer-replay-zero', ConsumerPathStreamNode::class);

    $handle = AgenticGraph::execute(
        'consumer-replay-zero',
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::SSE)
    );

    runConsumerPathExecuteGraphJob();
    $handle = $handle->refresh();

    $streamId = (string) $handle->streamId();
    $stored = storedStreamEvents($streamId);
    $replayed = iterator_to_array($handle->replayFrom(0));
    $terminals = executionTerminalEventsFrom($replayed);

    expect($stored)->not->toBeEmpty()
        ->and(array_map(fn (StreamEvent $event) => $event->seq(), $replayed))
        ->toBe(array_map(fn (StreamEvent $event) => $event->seq(), $stored))
        ->and($terminals)->toHaveCount(1)
        ->and($terminals[0]->payload()['status'])->toBe('completed');
});

it('replayFrom mid-stream cursor skips earlier store events', function () {
    Bus::fake();
    registerConsumerPathGraph('consumer-replay-mid', ConsumerPathStreamNode::class);

    $handle = AgenticGraph::execute(
        'consumer-replay-mid',
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::SSE)
    );

    runConsumerPathExecuteGraphJob();
    $handle = $handle->refresh();

    $streamId = (string) $handle->streamId();
    $stored = storedStreamEvents($streamId);
    $pivot = $stored[(int) floor(count($stored) / 2)];
    $cursor = $pivot->seq() - 1;

    $replayed = iterator_to_array($handle->replayFrom($cursor));
    $expected = array_values(array_filter(
        $stored,
        fn (StreamEvent $event) => $event->seq() >= $pivot->seq()
    ));

    expect($replayed)->not->toBeEmpty()
        ->and($replayed[0]->seq())->toBe($pivot->seq())
        ->and(array_map(fn (StreamEvent $event) => $event->seq(), $replayed))
        ->toBe(array_map(fn (StreamEvent $event) => $event->seq(), $expected));
});

it('reconstructed getExecution replayFrom reads persisted stream events', function () {
    Bus::fake();
    registerConsumerPathGraph('consumer-replay-reconstructed', ConsumerPathStreamNode::class);

    $handle = AgenticGraph::execute(
        'consumer-replay-reconstructed',
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::BROADCAST)
    );

    runConsumerPathExecuteGraphJob();

    $reconstructed = AgenticGraph::getExecution($handle->id());
    $replayed = iterator_to_array($reconstructed->replayFrom(0));
    $terminals = executionTerminalEventsFrom($replayed);

    expect($reconstructed->isAsync())->toBeTrue()
        ->and($terminals)->toHaveCount(1)
        ->and(collect($replayed)->contains(fn (StreamEvent $event) => $event->type() === 'text_delta'))->toBeTrue();
});

it('locks async sse as a store-served read shape via controller and replayFrom', function () {
    Bus::fake();
    registerConsumerPathGraph('consumer-async-sse-store', ConsumerPathStreamNode::class);

    $factory = app(StreamSinkFactory::class);
    $probeSession = new StreamSession(
        streamId: 'probe-stream',
        executionId: 'probe-exec',
        options: new StreamOptions(delivery: 'sse'),
        startedAtUnix: time()
    );
    expect($factory->create($probeSession, new StreamOptions(delivery: 'sse')))
        ->toBeInstanceOf(NullTransportSink::class);

    $handle = AgenticGraph::execute(
        'consumer-async-sse-store',
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::SSE)
    );

    runConsumerPathExecuteGraphJob();
    $handle = $handle->refresh();

    $descriptor = $handle->stream();
    $streamId = (string) $descriptor->streamId;
    $stored = storedStreamEvents($streamId);
    $storedTerminals = executionTerminalEventsFrom($stored);

    expect($descriptor->hasUrl())->toBeTrue()
        ->and($descriptor->streamUrl)->toBe(route(StreamDescriptor::ROUTE_NAME, ['streamId' => $streamId]))
        ->and($descriptor->isInline())->toBeFalse()
        ->and($storedTerminals)->toHaveCount(1)
        ->and($storedTerminals[0]->payload()['status'])->toBe('completed');

    $controller = invokeStreamControllerForStreamId($streamId);
    expect($controller['content'])->toContain('event: text_delta')
        ->and($controller['content'])->toContain('event: execution_terminal')
        ->and($controller['content'])->toContain('"status":"completed"')
        ->and($controller['elapsed'])->toBeLessThan(2.0);

    $reconstructed = AgenticGraph::getExecution($handle->id());
    $replayed = iterator_to_array($reconstructed->replayFrom(0));

    expect(executionTerminalEventsFrom($replayed))->toHaveCount(1)
        ->and(collect($replayed)->contains(fn (StreamEvent $event) => $event->type() === 'text_delta'))->toBeTrue();
});

it('dispatched job completion integrates store writes with stream controller output', function () {
    Bus::fake();
    registerConsumerPathGraph('consumer-job-complete', ConsumerPathStreamNode::class);

    $handle = AgenticGraph::execute(
        'consumer-job-complete',
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::ITERATOR)
    );

    runConsumerPathExecuteGraphJob();
    $handle = $handle->refresh();

    expect($handle->status())->toBe(ExecutionStatus::COMPLETED);

    $streamId = (string) $handle->streamId();
    $result = invokeStreamControllerForStreamId($streamId);

    expect($result['content'])->toContain('event: text_delta')
        ->and($result['content'])->toContain('event: execution_terminal')
        ->and($result['content'])->toContain('"status":"completed"')
        ->and($result['content'])->toContain('final_state')
        ->and($result['elapsed'])->toBeLessThan(2.0);
});

it('dispatched job interrupt integrates store writes with prompt controller termination', function () {
    Bus::fake();
    registerConsumerPathGraph('consumer-job-interrupt', ConsumerPathInterruptNode::class);

    $handle = AgenticGraph::execute(
        'consumer-job-interrupt',
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::SSE)
    );

    runConsumerPathExecuteGraphJob();
    $handle = $handle->refresh();

    expect($handle->status())->toBe(ExecutionStatus::INTERRUPTED);

    $streamId = (string) $handle->streamId();
    $storedTerminals = executionTerminalEventsFrom(storedStreamEvents($streamId));

    expect($storedTerminals)->toHaveCount(1)
        ->and($storedTerminals[0]->payload()['status'])->toBe('interrupted');

    $result = invokeStreamControllerForStreamId($streamId);

    expect($result['content'])->toContain('event: node_interrupt')
        ->and($result['content'])->toContain('event: execution_terminal')
        ->and($result['content'])->toContain('"status":"interrupted"')
        ->and($result['content'])->toContain('final_state')
        ->and($result['elapsed'])->toBeLessThan(2.0);
});
