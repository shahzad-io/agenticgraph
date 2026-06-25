<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\NodeExecutionFailed;
use Shahzad\AgenticGraph\Events\NodeExecutionTimed;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

class CollapseMiddlewareStreamNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        $this->streamEvent('text_delta', ['delta' => 'ok']);

        return $state->set('marker', 'done');
    }
}

class CollapsePlainNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('plain', true);
    }
}

class CollapseEmitThenThrowNode extends AbstractNode
{
    public static int $executeCount = 0;

    public function __construct(private readonly int $emitCount = 2) {}

    public function getRetryAttempts(): int
    {
        return 3;
    }

    public function execute(StateContract $state): StateContract
    {
        self::$executeCount++;

        for ($i = 0; $i < $this->emitCount; $i++) {
            $this->streamEvent('text_delta', ['delta' => (string) $i]);
        }

        throw new RuntimeException('retriable after emit');
    }
}

class CollapsePreEmitThrowNode extends AbstractNode
{
    protected ?string $name = 'pre-emit-throw';

    public static int $executeCount = 0;

    public function getRetryAttempts(): int
    {
        return 3;
    }

    public function execute(StateContract $state): StateContract
    {
        self::$executeCount++;

        if (self::$executeCount < 3) {
            throw new RuntimeException('retriable before emit');
        }

        $this->streamEvent('text_delta', ['delta' => 'finally']);

        return $state->set('recovered', true);
    }
}

class CollapseNonStreamingRetryNode extends AbstractNode
{
    public static int $executeCount = 0;

    public function getRetryAttempts(): int
    {
        return 3;
    }

    public function execute(StateContract $state): StateContract
    {
        self::$executeCount++;

        if (self::$executeCount < 2) {
            throw new RuntimeException('sync retriable');
        }

        return $state->set('sync_recovered', true);
    }
}

function registerCollapseGraph(string $name, string $nodeClass): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('start', $nodeClass)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register($name, $graph);
}

function collapseStreamingOptions(): ExecutionOptions
{
    return new ExecutionOptions(
        mode: ExecutionMode::SYNC,
        streaming: StreamingMode::ITERATOR,
    );
}

beforeEach(function () {
    CollapseEmitThenThrowNode::$executeCount = 0;
    CollapsePreEmitThrowNode::$executeCount = 0;
    CollapseNonStreamingRetryNode::$executeCount = 0;
});

it('routes streaming nodes through middleware and preserves output state', function () {
    Event::fake([NodeExecutionTimed::class]);
    Log::spy();
    config()->set('agenticgraph.logging.enabled', true);

    registerCollapseGraph('collapse-middleware-stream', CollapseMiddlewareStreamNode::class);

    $handle = AgenticGraph::execute(
        'collapse-middleware-stream',
        new State,
        collapseStreamingOptions()
    );

    $expectedNodeName = (new CollapseMiddlewareStreamNode)->getName();

    expect($handle->getFinalState()->get('marker'))->toBe('done');

    Event::assertDispatched(NodeExecutionTimed::class, fn (NodeExecutionTimed $event) => $event->node === $expectedNodeName);
    Log::shouldHaveReceived('debug')->with('Executing node', Mockery::any());
    Log::shouldHaveReceived('debug')->with('Completed node', Mockery::any());
});

it('does not retry streaming nodes after content emission (D27)', function () {
    Event::fake([NodeExecutionFailed::class]);
    registerCollapseGraph('collapse-emit-then-throw', CollapseEmitThenThrowNode::class);

    try {
        AgenticGraph::execute('collapse-emit-then-throw', new State, collapseStreamingOptions());
    } catch (RuntimeException) {
    }

    expect(CollapseEmitThenThrowNode::$executeCount)->toBe(1);

    Event::assertDispatched(NodeExecutionFailed::class, function (NodeExecutionFailed $event): bool {
        return $event->nodeName === 'start'
            && $event->retryAttempt === 1
            && $event->willRetry === false;
    });
});

it('retries streaming nodes on retriable failure before any content emission (D27)', function () {
    registerCollapseGraph('collapse-pre-emit-throw', CollapsePreEmitThrowNode::class);

    $handle = AgenticGraph::execute('collapse-pre-emit-throw', new State, collapseStreamingOptions());

    expect(CollapsePreEmitThrowNode::$executeCount)->toBe(3)
        ->and($handle->getFinalState()->get('recovered'))->toBeTrue();

    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
    $deltas = array_values(array_filter($events, fn ($event) => $event->type() === 'text_delta'));

    expect($deltas)->toHaveCount(1)
        ->and($deltas[0]->payload()['delta'] ?? null)->toBe('finally');
});

it('retries non-streaming nodes without emission gating', function () {
    registerCollapseGraph('collapse-non-streaming-retry', CollapseNonStreamingRetryNode::class);

    $handle = AgenticGraph::execute(
        'collapse-non-streaming-retry',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE)
    );

    expect(CollapseNonStreamingRetryNode::$executeCount)->toBe(2)
        ->and($handle->getFinalState()->get('sync_recovered'))->toBeTrue();
});

it('runs plain and streaming nodes through execute in all four mode combinations', function () {
    registerCollapseGraph('collapse-plain', CollapsePlainNode::class);
    registerCollapseGraph('collapse-stream', CollapseMiddlewareStreamNode::class);

    $plainSync = AgenticGraph::execute(
        'collapse-plain',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE)
    );
    expect($plainSync->getFinalState()->get('plain'))->toBeTrue();

    $plainStream = AgenticGraph::execute('collapse-plain', new State, collapseStreamingOptions());
    expect($plainStream->getFinalState()->get('plain'))->toBeTrue();

    $streamSync = AgenticGraph::execute(
        'collapse-stream',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE)
    );
    expect($streamSync->getFinalState()->get('marker'))->toBe('done');

    $streamStream = AgenticGraph::execute('collapse-stream', new State, collapseStreamingOptions());
    expect($streamStream->getFinalState()->get('marker'))->toBe('done');

    $streamEvents = iterator_to_array($streamStream->stream()->inline ?? new ArrayIterator([]));
    $deltas = array_filter($streamEvents, fn ($event) => $event->type() === 'text_delta');
    expect(count($deltas))->toBeGreaterThan(0);
});
