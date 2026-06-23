<?php

use Illuminate\Support\Facades\Bus;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamDescriptor;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;

class DescriptorAsyncStreamNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        if ($this->isStreaming()) {
            $context = app(StreamContext::class);
            $session = $context->session();
            $session->seq++;

            $context->sink()?->emit(new StreamEventImpl(
                streamId: $session->streamId,
                executionId: $session->executionId,
                nodeName: 'async',
                spanId: null,
                parentSpanId: null,
                type: 'text_delta',
                payload: ['delta' => 'async'],
                seq: $session->seq,
                nodeSeq: 1,
                occurredAt: new DateTimeImmutable
            ));

            return $state->set('streamed', true);
        }

        return $state;
    }
}

class DescriptorSyncIteratorNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        if ($this->isStreaming()) {
            $context = app(StreamContext::class);
            $session = $context->session();
            $session->seq++;

            $context->sink()?->emit(new StreamEventImpl(
                streamId: $session->streamId,
                executionId: $session->executionId,
                nodeName: 'sync',
                spanId: null,
                parentSpanId: null,
                type: 'text_delta',
                payload: ['delta' => 'iterator'],
                seq: $session->seq,
                nodeSeq: 1,
                occurredAt: new DateTimeImmutable
            ));

            return $state->set('path', 'streaming');
        }

        return $state->set('path', 'sync');
    }
}

function registerDescriptorAsyncGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('stream', DescriptorAsyncStreamNode::class)
        ->setEntryPoint('stream')
        ->addExitPoint('stream')
        ->build();

    AgenticGraph::register($name, $graph);
}

function registerDescriptorSyncIteratorGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('alpha', DescriptorSyncIteratorNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register($name, $graph);
}

function runDescriptorAsyncJob(ExecuteGraphJob $job): void
{
    $job->handle(
        app(GraphExecutor::class),
        app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)
    );
}

beforeEach(function () {
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    config()->set('agenticgraph.streaming.routes.enabled', true);
    config()->set('agenticgraph.streaming.routes.prefix', 'agentic-graph/streams');
});

it('returns async stream descriptor with route-generated streamUrl', function () {
    Bus::fake();
    registerDescriptorAsyncGraph('descriptor-async-sse');

    $handle = AgenticGraph::execute(
        'descriptor-async-sse',
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::SSE)
    );

    $job = null;
    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $dispatched) use (&$job): bool {
        $job = $dispatched;

        return true;
    });

    runDescriptorAsyncJob($job);

    $descriptor = $handle->stream();

    expect($descriptor->streamId)->not->toBeNull()
        ->and($descriptor->inline)->toBeNull()
        ->and($descriptor->mode)->toBe(StreamingMode::SSE)
        ->and($descriptor->streamUrl)->toBe(route(StreamDescriptor::ROUTE_NAME, ['streamId' => $descriptor->streamId]))
        ->and($descriptor->hasUrl())->toBeTrue()
        ->and($descriptor->isInline())->toBeFalse();
});

it('returns sync iterator descriptor with working inline stream', function () {
    registerDescriptorSyncIteratorGraph('descriptor-sync-iterator');

    $handle = AgenticGraph::execute(
        'descriptor-sync-iterator',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR)
    );

    $descriptor = $handle->stream();
    $events = iterator_to_array($descriptor->inline ?? new ArrayIterator([]));
    $types = array_map(fn ($event) => $event->type(), $events);

    expect($descriptor->isInline())->toBeTrue()
        ->and($descriptor->hasUrl())->toBeFalse()
        ->and($descriptor->streamUrl)->toBeNull()
        ->and($descriptor->mode)->toBe(StreamingMode::ITERATOR)
        ->and($types)->toContain('text_delta')
        ->and($handle->getFinalState()->get('path'))->toBe('streaming');
});

it('reconstructed async handle exposes consumable streamUrl from persisted streamId', function () {
    Bus::fake();
    registerDescriptorAsyncGraph('descriptor-reconstructed-async');

    $handle = AgenticGraph::execute(
        'descriptor-reconstructed-async',
        new State,
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::BROADCAST)
    );

    $job = null;
    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $dispatched) use (&$job): bool {
        $job = $dispatched;

        return true;
    });

    runDescriptorAsyncJob($job);

    $reconstructed = AgenticGraph::getExecution($handle->id());
    $descriptor = $reconstructed->stream();

    expect($reconstructed->isAsync())->toBeTrue()
        ->and($descriptor->streamId)->not->toBeNull()
        ->and($descriptor->streamUrl)->toBe(route(StreamDescriptor::ROUTE_NAME, ['streamId' => $descriptor->streamId]))
        ->and($descriptor->inline)->toBeNull()
        ->and($descriptor->hasUrl())->toBeTrue();
});

it('returns honest no-stream descriptor for NONE executions', function () {
    $graph = GraphBuilder::create('descriptor-none')
        ->addNode('start', DescriptorSyncIteratorNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register('descriptor-none', $graph);

    $handle = AgenticGraph::execute(
        'descriptor-none',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE)
    );

    $descriptor = $handle->stream();

    expect($descriptor->streamId)->toBeNull()
        ->and($descriptor->streamUrl)->toBeNull()
        ->and($descriptor->inline)->toBeNull()
        ->and($descriptor->mode)->toBe(StreamingMode::NONE)
        ->and($descriptor->hasStream())->toBeFalse();
});

it('resolves named stream route under configured prefix', function () {
    $streamId = 'stream-route-check';
    $prefix = (string) config('agenticgraph.streaming.routes.prefix', 'agentic-graph/streams');
    $expectedPath = '/'.trim($prefix, '/').'/'.$streamId;

    $url = route(StreamDescriptor::ROUTE_NAME, ['streamId' => $streamId]);
    $path = parse_url($url, PHP_URL_PATH);

    expect($path)->toBe($expectedPath)
        ->and($url)->toBe(route(StreamDescriptor::ROUTE_NAME, ['streamId' => $streamId]));
});
