<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamLifecycleEventTypes;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;

class StreamPayloadInterruptNode extends PrismNode
{
    protected ?string $name = 'review';

    public function execute(StateContract $state): StateContract
    {
        return $state->set('visited', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return true;
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Review structured content';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInterruptPayload(StateContract $state): array
    {
        return [
            'topic' => 'HITL demo',
            'draft' => 'Proposed answer text',
            'proposed_action' => 'approve_merge',
            'affected_records' => [101, 202],
        ];
    }
}

class StreamBaselineInterruptNode extends PrismNode
{
    protected ?string $name = 'approve';

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
}

function nodeInterruptEventFromIterator(string $graphName): mixed
{
    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::ITERATOR,
            interrupts: true
        )
    );

    foreach ($handle->stream()->inline ?? [] as $event) {
        if ($event->type() === StreamLifecycleEventTypes::NODE_INTERRUPT) {
            return $event;
        }
    }

    return null;
}

it('streams interrupt_payload on node_interrupt via iterator when getInterruptPayload returns content', function () {
    $graphName = 'stream-interrupt-payload-iterator-'.uniqid();
    $graph = GraphBuilder::create($graphName)
        ->addNode('review', StreamPayloadInterruptNode::class)
        ->setEntryPoint('review')
        ->addExitPoint('review')
        ->build();

    AgenticGraph::register($graphName, $graph);

    $event = nodeInterruptEventFromIterator($graphName);

    expect($event)->not->toBeNull();

    $payload = $event->payload();

    expect($payload)->toHaveKeys(['node_name', 'reason', 'checkpoint_id', 'interrupt_payload'])
        ->and($payload['node_name'])->toBe('review')
        ->and($payload['reason'])->toBe('Review structured content')
        ->and($payload['checkpoint_id'])->not->toBe('')
        ->and($payload['interrupt_payload'])->toMatchArray([
            'topic' => 'HITL demo',
            'draft' => 'Proposed answer text',
            'proposed_action' => 'approve_merge',
            'affected_records' => [101, 202],
        ]);
});

it('omits interrupt_payload on node_interrupt stream when getInterruptPayload is null', function () {
    $graphName = 'stream-interrupt-baseline-iterator-'.uniqid();
    $graph = GraphBuilder::create($graphName)
        ->addNode('approve', StreamBaselineInterruptNode::class)
        ->setEntryPoint('approve')
        ->addExitPoint('approve')
        ->build();

    AgenticGraph::register($graphName, $graph);

    $event = nodeInterruptEventFromIterator($graphName);

    expect($event)->not->toBeNull();

    $payload = $event->payload();

    expect($payload)->toHaveKeys(['node_name', 'reason', 'checkpoint_id'])
        ->and($payload)->not->toHaveKey('interrupt_payload')
        ->and($payload['node_name'])->toBe('approve')
        ->and($payload['reason'])->toBe('Needs approval');
});

it('streams interrupt_payload on node_interrupt via broadcast sink', function () {
    $graph = GraphBuilder::create('stream-interrupt-payload-broadcast')
        ->addNode('review', StreamPayloadInterruptNode::class)
        ->setEntryPoint('review')
        ->addExitPoint('review')
        ->build();

    app(GraphRegistry::class)->register('stream-interrupt-payload-broadcast', $graph);

    $capturedWire = null;

    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')
        ->atLeast()
        ->once()
        ->withArgs(function (array $channels, string $eventName, array $wire) use (&$capturedWire): bool {
            if ($eventName === StreamLifecycleEventTypes::NODE_INTERRUPT) {
                $capturedWire = $wire;
            }

            return true;
        });

    $session = new StreamSession(
        streamId: 'stream-interrupt-payload',
        executionId: 'exec-interrupt-payload',
        options: new StreamOptions(interrupts: true),
        startedAtUnix: time(),
        seq: 0
    );
    $sink = new BroadcastSink($broadcaster, $session->streamId);
    $context = new StreamContext($session, $sink);

    $manager = app(InterruptServiceInterface::class);
    $manager->handle('exec-interrupt-payload', 'stream-interrupt-payload-broadcast', 'review', new State, $context);

    expect($capturedWire)->not->toBeNull();

    $payload = $capturedWire['payload'];

    expect($payload)->toHaveKeys(['node_name', 'reason', 'checkpoint_id', 'interrupt_payload', 'stream'])
        ->and($payload['interrupt_payload'])->toMatchArray([
            'topic' => 'HITL demo',
            'draft' => 'Proposed answer text',
            'proposed_action' => 'approve_merge',
            'affected_records' => [101, 202],
        ]);
});

it('omits interrupt_payload on broadcast node_interrupt when getInterruptPayload is null', function () {
    $graph = GraphBuilder::create('stream-interrupt-baseline-broadcast')
        ->addNode('approve', StreamBaselineInterruptNode::class)
        ->setEntryPoint('approve')
        ->addExitPoint('approve')
        ->build();

    app(GraphRegistry::class)->register('stream-interrupt-baseline-broadcast', $graph);

    $capturedWire = null;

    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')
        ->atLeast()
        ->once()
        ->withArgs(function (array $channels, string $eventName, array $wire) use (&$capturedWire): bool {
            if ($eventName === StreamLifecycleEventTypes::NODE_INTERRUPT) {
                $capturedWire = $wire;
            }

            return true;
        });

    $session = new StreamSession(
        streamId: 'stream-interrupt-baseline',
        executionId: 'exec-interrupt-baseline',
        options: new StreamOptions(interrupts: true),
        startedAtUnix: time(),
        seq: 0
    );
    $sink = new BroadcastSink($broadcaster, $session->streamId);
    $context = new StreamContext($session, $sink);

    $manager = app(InterruptServiceInterface::class);
    $manager->handle('exec-interrupt-baseline', 'stream-interrupt-baseline-broadcast', 'approve', new State, $context);

    expect($capturedWire)->not->toBeNull();

    $payload = $capturedWire['payload'];

    expect($payload)->toHaveKeys(['node_name', 'reason', 'checkpoint_id', 'stream'])
        ->and($payload)->not->toHaveKey('interrupt_payload');
});

it('streams interrupt_payload via memory sink for direct interrupt manager path', function () {
    $graph = GraphBuilder::create('stream-interrupt-payload-memory')
        ->addNode('review', StreamPayloadInterruptNode::class)
        ->setEntryPoint('review')
        ->addExitPoint('review')
        ->build();

    app(GraphRegistry::class)->register('stream-interrupt-payload-memory', $graph);

    $sink = new MemorySink;
    $session = new StreamSession(
        streamId: 'stream-memory-interrupt',
        executionId: 'exec-memory-interrupt',
        options: new StreamOptions(interrupts: true),
        startedAtUnix: time(),
        seq: 0
    );
    $context = new StreamContext($session, $sink);

    app(InterruptServiceInterface::class)->handle(
        'exec-memory-interrupt',
        'stream-interrupt-payload-memory',
        'review',
        new State,
        $context
    );

    $interruptEvent = collect($sink->events())->first(
        fn ($event) => $event->type() === StreamLifecycleEventTypes::NODE_INTERRUPT
    );

    expect($interruptEvent)->not->toBeNull();

    $payload = $interruptEvent->payload();

    expect($payload['interrupt_payload'])->toMatchArray([
        'draft' => 'Proposed answer text',
        'proposed_action' => 'approve_merge',
    ]);
});
