<?php

use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\NodeExecutionFailed;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Tracing\TraceContext;

class StreamEventPromotionNode extends AbstractNode
{
    protected ?string $name = 'promo-node';

    public function execute(StateContract $state): StateContract
    {
        $this->streamEvent('text_delta', ['delta' => 'x']);

        return $state->set('done', true);
    }
}

class StreamEventThenThrowNode extends AbstractNode
{
    protected ?string $name = 'stream-event-throw';

    public static int $executeCount = 0;

    public function getRetryAttempts(): int
    {
        return 3;
    }

    public function execute(StateContract $state): StateContract
    {
        self::$executeCount++;
        $this->streamEvent('text_delta', ['delta' => 'partial']);
        throw new RuntimeException('retriable after streamEvent');
    }
}

function bindPromotionStreamContext(MemorySink $sink): StreamSession
{
    $session = new StreamSession(
        streamId: 'stream-promo-1',
        executionId: 'exec-promo-1',
        options: new StreamOptions(delivery: 'iterator'),
        startedAtUnix: time()
    );
    app()->instance(StreamContext::class, new StreamContext($session, $sink));
    app()->instance(TraceContext::class, new TraceContext('exec-promo-1', 'root-span'));

    return $session;
}

beforeEach(function () {
    StreamEventThenThrowNode::$executeCount = 0;
});

it('emits via streamEvent in a streaming run with correct type payload and seq', function () {
    $sink = new MemorySink;
    $session = bindPromotionStreamContext($sink);
    $trace = app(TraceContext::class);
    $node = new StreamEventPromotionNode;

    $node->execute(new State);

    $events = iterator_to_array($sink->events());
    expect($events)->toHaveCount(1);

    $event = $events[0];
    expect($event->type())->toBe('text_delta')
        ->and($event->payload())->toBe(['delta' => 'x'])
        ->and($event->nodeName())->toBe('promo-node')
        ->and($event->streamId())->toBe($session->streamId)
        ->and($event->executionId())->toBe($session->executionId)
        ->and($event->seq())->toBe(1)
        ->and($event->spanId())->toBe($trace->getSpanId())
        ->and($event->parentSpanId())->toBe($trace->getParentSpanId());

    app()->forgetInstance(StreamContext::class);
    app()->forgetInstance(TraceContext::class);
});

it('no-ops streamEvent when not streaming', function () {
    $sink = new MemorySink;
    app()->forgetInstance(StreamContext::class);

    $node = new StreamEventPromotionNode;
    $result = $node->execute(new State);

    expect($result->get('done'))->toBeTrue()
        ->and(iterator_count($sink->events()))->toBe(0);
});

it('matches hand-built StreamEventImpl for type payload and monotonic seq', function () {
    $sink = new MemorySink;
    $session = bindPromotionStreamContext($sink);
    $trace = app(TraceContext::class);

    $session->seq++;
    $handBuilt = new StreamEventImpl(
        streamId: $session->streamId,
        executionId: $session->executionId,
        nodeName: 'promo-node',
        spanId: $trace->getSpanId(),
        parentSpanId: $trace->getParentSpanId(),
        type: 'text_delta',
        payload: ['delta' => 'x'],
        seq: $session->seq,
        nodeSeq: null,
        occurredAt: new DateTimeImmutable
    );
    $sink->emit($handBuilt);

    (new StreamEventPromotionNode)->execute(new State);

    $events = iterator_to_array($sink->events());
    expect($events)->toHaveCount(2)
        ->and($events[0]->type())->toBe($events[1]->type())
        ->and($events[0]->payload())->toBe($events[1]->payload())
        ->and($events[0]->nodeName())->toBe($events[1]->nodeName())
        ->and($events[1]->seq())->toBe($events[0]->seq() + 1);

    app()->forgetInstance(StreamContext::class);
    app()->forgetInstance(TraceContext::class);
});

it('applies the content-emission retry gate when failing after streamEvent emission', function () {
    Event::fake([NodeExecutionFailed::class]);

    $graph = GraphBuilder::create('stream-event-d27')
        ->addNode('start', StreamEventThenThrowNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();
    AgenticGraph::register('stream-event-d27', $graph);

    try {
        AgenticGraph::execute(
            'stream-event-d27',
            new State,
            new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR)
        );
    } catch (RuntimeException) {
    }

    expect(StreamEventThenThrowNode::$executeCount)->toBe(1);

    Event::assertDispatched(NodeExecutionFailed::class, function (NodeExecutionFailed $event): bool {
        return $event->nodeName === 'start'
            && $event->retryAttempt === 1
            && $event->willRetry === false;
    });
});
