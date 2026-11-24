<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;
use Shahzad\AgenticGraph\Testing\Llm;

class PrivateChannelAnswerNode extends PrismNode
{
    protected ?string $model = 'openai:test-model';

    public function execute(StateContract $state): StateContract
    {
        return $state->set('answer', $this->llm()->prompt('a')->text());
    }
}

function privateChannelRun(): array
{
    $stream = frameRecorder();
    useStreamBroadcaster(frameRecorder(), $stream);
    Llm::fake(['hello']);
    AgenticGraph::register('private-channel', GraphBuilder::create('private-channel')
        ->addNode('agent', PrivateChannelAnswerNode::class)
        ->setEntryPoint('agent')
        ->addExitPoint('agent')
        ->build());
    $handle = AgenticGraph::broadcast('private-channel', new State, async: false);

    return [$handle, array_values(array_unique(array_column($stream->frames, 'channel')))];
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
});

// From the scratch reproduction "gap 4".
it('gap 4: frames go to a private channel and the core authorizes it', function () {
    [$handle, $channels] = privateChannelRun();
    $name = 'private-agenticgraph.stream.'.$handle->streamId();

    expect($channels)->toBe([$name])
        ->and($handle->broadcastChannel())->toBe($name)
        ->and(BroadcastSink::privateChannelName((string) $handle->streamId()))->toBe($name);
});

it('uses the public name with streaming.broadcast.private=false, and broadcastChannel() returns it', function () {
    config()->set('agenticgraph.streaming.broadcast.private', false);

    [$handle, $channels] = privateChannelRun();
    $name = 'agenticgraph.stream.'.$handle->streamId();

    expect($channels)->toBe([$name])
        ->and($handle->broadcastChannel())->toBe($name);
});

it('keeps channel_prefix under the private- prefix', function () {
    config()->set('agenticgraph.streaming.broadcast.channel_prefix', 'app.streams.');

    [$handle, $channels] = privateChannelRun();

    expect($channels)->toBe(['private-app.streams.'.$handle->streamId()])
        ->and($handle->broadcastChannel())->toBe('private-app.streams.'.$handle->streamId());
});
