<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamResumePayload;

class CENodeA extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('a', true);
    }
}

class CENodeEnd extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class CEStreamingNode extends AbstractNode
{
    protected ?string $name = 'ce-stream';

    public function execute(StateContract $state): StateContract
    {
        if ($this->isStreaming()) {
            $this->streamEvent('text_delta', ['delta' => 'continue']);

            return $state->set('streamed', true);
        }

        return $state;
    }
}

it('continues execution from a node', function () {
    $graph = GraphBuilder::create('ce-graph')
        ->addNode('a', CENodeA::class)
        ->addNode('end', CENodeEnd::class)
        ->addEdge('a', 'end')
        ->setEntryPoint('a')
        ->addExitPoint('end')
        ->build();
    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register('ce-graph', $graph);
    $job = new ContinueExecutionJob('ce-graph', 'exec-2', (new State)->toArray(), 'a');
    $job->handle(new GraphExecutor, app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class));
    $list = (new DatabaseStorage)->list('ce-graph');
    expect($list)->not()->toBeEmpty();
});

it('uses provided stream id for streaming', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');

    $graph = GraphBuilder::create('ce-stream-id')
        ->addNode('a', CEStreamingNode::class)
        ->setEntryPoint('a')
        ->addExitPoint('a')
        ->build();
    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register('ce-stream-id', $graph);

    $streamId = uniqid('stream_', true);
    $options = new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::ITERATOR);

    $job = new ContinueExecutionJob(
        'ce-stream-id',
        'exec-3',
        (new State)->toArray(),
        'a',
        null,
        $options,
        $streamId,
        0
    );
    $job->handle(new GraphExecutor, app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class));

    $store = app(StreamStoreInterface::class);
    $events = iterator_to_array($store->readFrom($streamId, 1, 5));

    expect($events)->not->toBeEmpty()
        ->and($events[0]->streamId())->toBe($streamId);
});

it('continues sequence correctly', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');

    $graph = GraphBuilder::create('ce-seq')
        ->addNode('a', CEStreamingNode::class)
        ->setEntryPoint('a')
        ->addExitPoint('a')
        ->build();
    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register('ce-seq', $graph);

    $streamId = uniqid('stream_', true);
    $options = new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::ITERATOR);

    $job = new ContinueExecutionJob(
        'ce-seq',
        'exec-4',
        (new State)->toArray(),
        'a',
        null,
        $options,
        $streamId,
        7
    );
    $job->handle(new GraphExecutor, app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class));

    $store = app(StreamStoreInterface::class);
    $events = iterator_to_array($store->readFrom($streamId, 8, 5));

    expect($events)->not->toBeEmpty()
        ->and($events[0]->seq())->toBe(8);
});

it('emits stream resume event', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');

    $graph = GraphBuilder::create('ce-resume')
        ->addNode('a', CEStreamingNode::class)
        ->setEntryPoint('a')
        ->addExitPoint('a')
        ->build();
    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register('ce-resume', $graph);

    $streamId = uniqid('stream_', true);
    $options = new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::ITERATOR);
    $resumePayload = new StreamResumePayload(
        fromSeq: 5,
        resumedAtSeq: 6,
        gapEvents: [],
        checkpointId: 'checkpoint-1'
    );

    $job = new ContinueExecutionJob(
        'ce-resume',
        'exec-5',
        (new State)->toArray(),
        'a',
        null,
        $options,
        $streamId,
        5,
        $resumePayload
    );
    $job->handle(new GraphExecutor, app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class));

    $store = app(StreamStoreInterface::class);
    $events = iterator_to_array($store->readFrom($streamId, 6, 10));
    $types = array_map(fn ($event) => $event->type(), $events);

    expect($types)->toContain('stream_resume');
});
