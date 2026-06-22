<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\Contracts\StreamableNodeInterface;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamErrorHandler;

class PolicyStreamNode extends AbstractNode implements StreamableNodeInterface
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        $session = app(StreamContext::class)->session();
        $session->seq++;

        $sink->emit(new StreamEventImpl(
            streamId: $session->streamId,
            executionId: $session->executionId,
            nodeName: 'policy-stream',
            spanId: null,
            parentSpanId: null,
            type: 'text_delta',
            payload: ['delta' => 'policy-test'],
            seq: $session->seq,
            nodeSeq: 1,
            occurredAt: new DateTimeImmutable
        ));

        return $state->set('streamed', true);
    }
}

function registerPolicyStreamGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('start', PolicyStreamNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register($name, $graph);
}

function bindThrowingStreamStore(): void
{
    $store = new class implements StreamStoreInterface
    {
        public function append(StreamEvent $event): void
        {
            throw new RuntimeException('store persist failed');
        }

        public function readFrom(string $streamId, int $fromSeq, int $limit = 200): \Generator
        {
            if (false) {
                yield $streamId;
            }
        }

        public function lastSeq(string $streamId): ?int
        {
            return null;
        }

        public function truncate(string $streamId, int $beforeSeq): void {}
    };

    app()->forgetInstance(StreamStoreInterface::class);
    app()->instance(StreamStoreInterface::class, $store);
}

afterEach(function () {
    Mockery::close();
    app()->forgetInstance(StreamStoreInterface::class);
});

it('StreamErrorHandler treats store errors as non-fatal by default', function () {
    config()->set('agenticgraph.streaming.errors.store_fatal', false);

    $handler = new StreamErrorHandler;
    $event = new StreamEventImpl(
        streamId: 'stream-policy',
        executionId: 'exec-policy',
        nodeName: 'node',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'x'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable
    );

    $decision = $handler->decide(new RuntimeException('store down'), $event, 'store');

    expect($decision->fatal)->toBeFalse();
});

it('StreamErrorHandler treats transport errors as fatal by default', function () {
    $handler = new StreamErrorHandler;
    $event = new StreamEventImpl(
        streamId: 'stream-policy',
        executionId: 'exec-policy',
        nodeName: 'node',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'x'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable
    );

    $decision = $handler->decide(new RuntimeException('broadcast down'), $event, 'transport');

    expect($decision->fatal)->toBeTrue();
});

it('StreamErrorHandler honors store_fatal config override', function () {
    config()->set('agenticgraph.streaming.errors.store_fatal', true);

    $handler = new StreamErrorHandler;
    $event = new StreamEventImpl(
        streamId: 'stream-policy',
        executionId: 'exec-policy',
        nodeName: 'node',
        spanId: null,
        parentSpanId: null,
        type: 'text_delta',
        payload: ['delta' => 'x'],
        seq: 1,
        nodeSeq: 1,
        occurredAt: new DateTimeImmutable
    );

    expect($handler->decide(new RuntimeException('store down'), $event, 'store')->fatal)->toBeTrue();
});

it('completes streaming execution when store persistence fails', function () {
    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $logged) use (&$warnings): void {
        if ($logged->level === 'warning') {
            $warnings[] = $logged;
        }
    });
    Event::fake([GraphExecutionFailed::class]);
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.streaming.errors.store_fatal', false);

    bindThrowingStreamStore();
    registerPolicyStreamGraph('policy-store-nonfatal');

    $handle = AgenticGraph::execute(
        'policy-store-nonfatal',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR)
    );

    expect($handle->status())->toBe(ExecutionStatus::COMPLETED);

    $eventTypes = [];
    foreach ($handle->stream()->inline ?? [] as $event) {
        $eventTypes[] = $event->type();
    }

    expect($eventTypes)->toContain('stream_error')
        ->and($eventTypes)->toContain('text_delta');

    expect($warnings)->not->toBeEmpty()
        ->and($warnings[0]->message)->toBe('Non-fatal stream error')
        ->and($warnings[0]->context['stage'] ?? null)->toBe('store');
    Event::assertNotDispatched(GraphExecutionFailed::class);

    $record = app(ExecutionStore::class)->get($handle->id());
    expect($record?->status)->toBe(ExecutionStatus::COMPLETED);
});

it('marks streaming execution failed when transport delivery fails', function () {
    Event::fake([GraphExecutionFailed::class]);
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.streaming.errors.store_fatal', false);

    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')
        ->andReturnUsing(function ($channels, $event, $payload): void {
            if ($event === 'text_delta') {
                throw new RuntimeException('broadcast transport failed');
            }
        });
    app()->instance(Broadcaster::class, $broadcaster);

    registerPolicyStreamGraph('policy-transport-fatal');

    try {
        AgenticGraph::execute(
            'policy-transport-fatal',
            new State,
            new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::BROADCAST)
        );
        expect(false)->toBeTrue('Expected transport failure to abort execution');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('broadcast transport failed');
    }

    $executionId = \Illuminate\Support\Facades\DB::table('agenticgraph_executions')
        ->where('graph_name', 'policy-transport-fatal')
        ->orderByDesc('created_at')
        ->value('id');

    $record = app(ExecutionStore::class)->get($executionId);

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ExecutionStatus::FAILED);

    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
});

it('marks streaming execution failed when store_fatal override is enabled inside node streaming', function () {
    Event::fake([GraphExecutionFailed::class]);
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.streaming.errors.store_fatal', true);

    $store = new class implements StreamStoreInterface
    {
        public function append(StreamEvent $event): void
        {
            if ($event->type() === 'text_delta') {
                throw new RuntimeException('store persist failed');
            }
        }

        public function readFrom(string $streamId, int $fromSeq, int $limit = 200): \Generator
        {
            if (false) {
                yield $streamId;
            }
        }

        public function lastSeq(string $streamId): ?int
        {
            return null;
        }

        public function truncate(string $streamId, int $beforeSeq): void {}
    };

    app()->forgetInstance(StreamStoreInterface::class);
    app()->instance(StreamStoreInterface::class, $store);
    registerPolicyStreamGraph('policy-store-fatal-config');

    try {
        AgenticGraph::execute(
            'policy-store-fatal-config',
            new State,
            new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR)
        );
        expect(false)->toBeTrue('Expected configured store failure to abort execution');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('store persist failed');
    }

    $executionId = \Illuminate\Support\Facades\DB::table('agenticgraph_executions')
        ->where('graph_name', 'policy-store-fatal-config')
        ->orderByDesc('created_at')
        ->value('id');

    $record = app(ExecutionStore::class)->get($executionId);

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ExecutionStatus::FAILED);

    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
});
