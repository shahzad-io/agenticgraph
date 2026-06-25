<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;

class SetupFailureStreamNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        if ($this->isStreaming()) {
            return $state->set('streamed', true);
        }

        return $state;
    }
}

class SetupThrowingStreamStore implements StreamStoreInterface
{
    public function append(StreamEvent $event): void
    {
        if ($event->type() === 'graph_start') {
            throw new RuntimeException('stream setup failed');
        }
    }

    public function readFrom(string $streamId, int $fromSeq, int $limit = 200): Generator
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
}

function registerSetupFailureStreamGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('start', SetupFailureStreamNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register($name, $graph);
}

function bindSetupThrowingStreamStore(): void
{
    app()->forgetInstance(StreamStoreInterface::class);
    app()->instance(StreamStoreInterface::class, new SetupThrowingStreamStore);
}

afterEach(function () {
    Mockery::close();
    app()->forgetInstance(StreamStoreInterface::class);
    app()->forgetInstance(Broadcaster::class);
});

it('marks streaming execution failed when setup throws before performStreamingExecution', function () {
    Event::fake([GraphExecutionFailed::class]);
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.streaming.errors.store_fatal', true);

    bindSetupThrowingStreamStore();
    registerSetupFailureStreamGraph('stream-setup-failure');

    try {
        AgenticGraph::execute(
            'stream-setup-failure',
            new State,
            new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR)
        );
        expect(false)->toBeTrue('Expected streaming setup to throw');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('stream setup failed');
    }

    $executionId = DB::table('agenticgraph_executions')
        ->where('graph_name', 'stream-setup-failure')
        ->orderByDesc('created_at')
        ->value('id');

    $record = app(ExecutionStore::class)->get($executionId);

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ExecutionStatus::FAILED)
        ->and($record->error)->toBe('stream setup failed');

    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);

    Event::assertDispatched(GraphExecutionFailed::class, function (GraphExecutionFailed $event) use ($executionId) {
        return $event->executionId === $executionId
            && $event->graphName === 'stream-setup-failure'
            && $event->exception instanceof RuntimeException
            && $event->exception->getMessage() === 'stream setup failed';
    });
});

it('closes the stream sink with ERROR when setup fails during graph_start emit', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.streaming.errors.store_fatal', false);

    $streamCloseStatuses = [];
    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')
        ->andReturnUsing(function ($channels, $event, $payload) use (&$streamCloseStatuses): void {
            if ($event === 'graph_start') {
                throw new RuntimeException('stream setup failed');
            }

            if ($event === 'stream_close') {
                $streamCloseStatuses[] = $payload['status'] ?? null;
            }
        });
    app()->instance(Broadcaster::class, $broadcaster);

    registerSetupFailureStreamGraph('stream-setup-sink-close');

    try {
        AgenticGraph::execute(
            'stream-setup-sink-close',
            new State,
            new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::BROADCAST)
        );
        expect(false)->toBeTrue('Expected streaming setup to throw');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('stream setup failed');
    }

    expect($streamCloseStatuses)->not->toBeEmpty()
        ->and(end($streamCloseStatuses))->toBe('error');
});
