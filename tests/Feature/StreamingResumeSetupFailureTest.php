<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\Contracts\StreamableNodeInterface;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumePayload;

class ResumeSetupFailureStreamNode extends AbstractNode implements StreamableNodeInterface
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        return $state->set('streamed', true);
    }
}

class SetupThrowingResumeStreamStore implements StreamStoreInterface
{
    public function append(StreamEvent $event): void
    {
        if ($event->type() === 'stream_resume') {
            throw new RuntimeException('stream resume setup failed');
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
}

function registerResumeSetupFailureGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('review', ResumeSetupFailureStreamNode::class)
        ->setEntryPoint('review')
        ->addExitPoint('review')
        ->build();

    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register($name, $graph);
}

afterEach(function () {
    Mockery::close();
    app()->forgetInstance(StreamStoreInterface::class);
    app()->forgetInstance(Broadcaster::class);
});

it('marks interrupted streaming resume failed when setup throws before performStreamingExecution', function () {
    Event::fake([GraphExecutionFailed::class]);
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.streaming.errors.store_fatal', true);

    app()->forgetInstance(StreamStoreInterface::class);
    app()->instance(StreamStoreInterface::class, new SetupThrowingResumeStreamStore);

    $graphName = 'stream-resume-setup-failure';
    registerResumeSetupFailureGraph($graphName);

    $executionId = 'exec_resume_setup_'.Str::uuid();
    $repository = app(ExecutionRepository::class);
    $payload = State::toStoragePayload(new State(['interrupted' => true]));
    $repository->queued($executionId, $graphName, $payload, 'stream-resume-setup');
    $repository->started($executionId, $graphName, $payload, 'stream-resume-setup');
    $repository->interrupted($executionId);

    $graph = app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->get($graphName);
    $resumePayload = new StreamResumePayload(
        fromSeq: 3,
        resumedAtSeq: 4,
        gapEvents: [],
        checkpointId: 'checkpoint-resume-setup'
    );

    try {
        app(GraphExecutor::class)->executeWithStreamingFromNode(
            $graph,
            new State(['interrupted' => true]),
            'review',
            $executionId,
            new StreamOptions(delivery: 'iterator'),
            null,
            $resumePayload
        );
        expect(false)->toBeTrue('Expected streaming resume setup to throw');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('stream resume setup failed');
    }

    $record = app(ExecutionStore::class)->get($executionId);

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ExecutionStatus::FAILED)
        ->and($record->error)->toBe('stream resume setup failed');

    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);

    Event::assertDispatched(GraphExecutionFailed::class, function (GraphExecutionFailed $event) use ($executionId, $graphName) {
        return $event->executionId === $executionId
            && $event->graphName === $graphName
            && $event->exception instanceof RuntimeException
            && $event->exception->getMessage() === 'stream resume setup failed';
    });
});

it('closes the stream sink with ERROR when streaming resume setup fails', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.streaming.errors.store_fatal', false);

    $streamCloseStatuses = [];
    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')
        ->andReturnUsing(function ($channels, $event, $payload) use (&$streamCloseStatuses): void {
            if ($event === 'stream_resume') {
                throw new RuntimeException('stream resume setup failed');
            }

            if ($event === 'stream_close') {
                $streamCloseStatuses[] = $payload['status'] ?? null;
            }
        });
    app()->instance(Broadcaster::class, $broadcaster);

    $graphName = 'stream-resume-sink-close';
    registerResumeSetupFailureGraph($graphName);

    $executionId = 'exec_resume_sink_'.Str::uuid();
    $repository = app(ExecutionRepository::class);
    $payload = State::toStoragePayload(new State);
    $repository->queued($executionId, $graphName, $payload, 'stream-resume-close');
    $repository->started($executionId, $graphName, $payload, 'stream-resume-close');
    $repository->interrupted($executionId);

    $graph = app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->get($graphName);
    $resumePayload = new StreamResumePayload(
        fromSeq: 1,
        resumedAtSeq: 2,
        gapEvents: [],
        checkpointId: 'checkpoint-resume-close'
    );

    try {
        app(GraphExecutor::class)->executeWithStreamingFromNode(
            $graph,
            new State,
            'review',
            $executionId,
            new StreamOptions(delivery: 'broadcast'),
            null,
            $resumePayload
        );
        expect(false)->toBeTrue('Expected streaming resume setup to throw');
    } catch (RuntimeException) {
        // expected
    }

    expect($streamCloseStatuses)->not->toBeEmpty()
        ->and(end($streamCloseStatuses))->toBe('error');
});
