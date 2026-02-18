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

class SimpleNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

it('dispatches async job', function () {
    Bus::fake();
    $graph = GraphBuilder::create('q')
        ->addNode('start', SimpleNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();
    $executor = new GraphExecutor;
    $executor->executeAsync($graph, new State);
    Bus::assertDispatched(ExecuteGraphJob::class);
});

it('dispatches async stream workflow job', function () {
    Bus::fake();
    $graph = GraphBuilder::create('q-stream')
        ->addNode('start', SimpleNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();
    AgenticGraph::register('q-stream', $graph);

    $options = new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::BROADCAST);
    $handle = AgenticGraph::execute('q-stream', new State, $options);

    Bus::assertDispatched(ExecuteGraphJob::class, function ($job) {
        return $job->executionOptions !== null
            && $job->streamId !== null
            && $job->streamSeq === 0;
    });

    expect($handle->id())->not->toBeEmpty()
        ->and($handle->streamId())->not->toBeEmpty();
});

it('dispatches async stream workflow with stream options', function () {
    Bus::fake();
    $graph = GraphBuilder::create('q-stream-options')
        ->addNode('start', SimpleNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();
    AgenticGraph::register('q-stream-options', $graph);

    $options = new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::BROADCAST);
    AgenticGraph::execute('q-stream-options', new State, $options);

    Bus::assertDispatched(ExecuteGraphJob::class, function ($job) use ($options) {
        return $job->executionOptions !== null
            && $job->executionOptions->streaming === $options->streaming
            && $job->executionOptions->mode === $options->mode;
    });
});

it('serializes stream options on async stream jobs', function () {
    Bus::fake();
    $graph = GraphBuilder::create('q-stream-serialize')
        ->addNode('start', SimpleNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();
    AgenticGraph::register('q-stream-serialize', $graph);

    $options = new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::BROADCAST);
    AgenticGraph::execute('q-stream-serialize', new State, $options);

    Bus::assertDispatched(ExecuteGraphJob::class, function ($job) use ($options) {
        $serialized = serialize($job);
        $copy = unserialize($serialized);

        return $copy instanceof ExecuteGraphJob
            && $copy->executionOptions !== null
            && $copy->executionOptions->streaming === $options->streaming
            && $copy->executionOptions->mode === $options->mode;
    });
});
