<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionHandle;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\State\StateManager;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamNodeCompletePayloadBuilder;

class StateCaptureMutatingNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state
            ->set('counter', ($state->get('counter', 0)) + 1)
            ->set('nested', [
                'items' => [
                    ['id' => 1, 'tags' => ['a', 'b']],
                    ['id' => 2, 'meta' => ['depth' => ['level' => 3]]],
                ],
                'flags' => ['enabled' => true],
            ]);
    }
}

class StateCaptureNoOpNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

function stateCaptureStreamingOptions(): ExecutionOptions
{
    return new ExecutionOptions(
        mode: ExecutionMode::SYNC,
        streaming: StreamingMode::ITERATOR,
    );
}

/**
 * @return array<int, StreamEvent>
 */
function stateCaptureInlineEvents(ExecutionHandle $handle): array
{
    return iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
}

function stateCaptureNodeCompletePayload(ExecutionHandle $handle): array
{
    $events = stateCaptureInlineEvents($handle);
    $nodeComplete = null;
    foreach ($events as $event) {
        if ($event->type() === 'node_complete') {
            $nodeComplete = $event;
            break;
        }
    }

    expect($nodeComplete)->not->toBeNull();

    $payload = $nodeComplete->payload();
    expect($payload)->toBeArray();

    return $payload;
}

/**
 * @return array<int, StreamEvent>
 */
function stateCaptureStoredEvents(ExecutionHandle $handle): array
{
    $streamId = $handle->streamId();
    expect($streamId)->not->toBeNull()->not->toBe('');

    return iterator_to_array(app(StreamStoreInterface::class)->readFrom((string) $streamId, 1, 1000));
}

function registerStateCaptureGraph(string $name, string $nodeClass): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('alpha', $nodeClass)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register($name, $graph);
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.capture.state', 'none');
    config()->set('agenticgraph.streaming.store.driver', 'database');
});

it('leaves node_complete payload unchanged when stream state capture is none (default)', function () {
    registerStateCaptureGraph('state-capture-default', StateCaptureMutatingNode::class);

    $handle = AgenticGraph::execute(
        'state-capture-default',
        new State(['counter' => 0]),
        stateCaptureStreamingOptions()
    );

    $payload = stateCaptureNodeCompletePayload($handle);

    expect($payload)->toHaveKeys(['node_name', 'duration_ms', 'stream'])
        ->and($payload['node_name'])->toBe('alpha')
        ->and($payload['duration_ms'])->toBeFloat()
        ->and($payload)->not->toHaveKey('state_diff')
        ->and($payload)->not->toHaveKey('state');
});

it('attaches state_diff on node_complete when stream state capture is diff', function () {
    config()->set('agenticgraph.streaming.capture.state', 'diff');
    registerStateCaptureGraph('state-capture-diff', StateCaptureMutatingNode::class);

    $handle = AgenticGraph::execute(
        'state-capture-diff',
        new State(['counter' => 0, 'existing' => 'keep']),
        stateCaptureStreamingOptions()
    );

    $payload = stateCaptureNodeCompletePayload($handle);

    expect($payload)->toHaveKey('state_diff')
        ->and($payload['state_diff']['added'])->toHaveKey('nested')
        ->and($payload['state_diff']['modified']['counter'])->toBe(['from' => 0, 'to' => 1])
        ->and($payload['state_diff']['removed'])->toBe([]);
});

it('serializes diff mode node_complete through the stream store', function () {
    config()->set('agenticgraph.streaming.capture.state', 'diff');
    registerStateCaptureGraph('state-capture-diff-store', StateCaptureMutatingNode::class);

    $handle = AgenticGraph::execute(
        'state-capture-diff-store',
        new State(['counter' => 0]),
        stateCaptureStreamingOptions()
    );

    $stored = stateCaptureStoredEvents($handle);
    $nodeComplete = null;
    foreach ($stored as $event) {
        if ($event->type() === 'node_complete') {
            $nodeComplete = $event;
            break;
        }
    }

    expect($nodeComplete)->not->toBeNull();
    $payload = $nodeComplete->payload();
    expect($payload['state_diff']['modified']['counter']['to'])->toBe(1)
        ->and($payload['state_diff']['added']['nested']['items'][1]['meta']['depth']['level'])->toBe(3);
});

it('attaches full output state on node_complete when stream state capture is full', function () {
    config()->set('agenticgraph.streaming.capture.state', 'full');
    registerStateCaptureGraph('state-capture-full', StateCaptureMutatingNode::class);

    $handle = AgenticGraph::execute(
        'state-capture-full',
        new State(['counter' => 0]),
        stateCaptureStreamingOptions()
    );

    $payload = stateCaptureNodeCompletePayload($handle);

    expect($payload)->toHaveKey('state')
        ->and($payload)->not->toHaveKey('state_diff')
        ->and($payload['state']['__agenticgraph_state']['data']['counter'])->toBe(1)
        ->and($payload['state']['__agenticgraph_state']['data']['nested']['items'][0]['tags'])->toBe(['a', 'b']);
});

it('produces an empty diff for a node that does not change state', function () {
    config()->set('agenticgraph.streaming.capture.state', 'diff');
    registerStateCaptureGraph('state-capture-noop', StateCaptureNoOpNode::class);

    $handle = AgenticGraph::execute(
        'state-capture-noop',
        new State(['stable' => 'value']),
        stateCaptureStreamingOptions()
    );

    $payload = stateCaptureNodeCompletePayload($handle);

    expect($payload['state_diff'])->toBe([
        'added' => [],
        'modified' => [],
        'removed' => [],
    ]);
});

it('builds payload modes via StreamNodeCompletePayloadBuilder', function () {
    $manager = app(StateManager::class);
    $input = new State(['a' => 1]);
    $output = new State(['a' => 2, 'b' => ['nested' => true]]);

    config()->set('agenticgraph.streaming.capture.state', 'none');
    $none = StreamNodeCompletePayloadBuilder::fromConfig($manager)->build('n', 1.0, $input, $output);
    expect($none)->toBe(['node_name' => 'n', 'duration_ms' => 1.0]);

    config()->set('agenticgraph.streaming.capture.state', 'diff');
    $diff = StreamNodeCompletePayloadBuilder::fromConfig($manager)->build('n', 1.0, $input, $output);
    expect($diff['state_diff']['modified']['a'])->toBe(['from' => 1, 'to' => 2])
        ->and($diff['state_diff']['added']['b'])->toBe(['nested' => true]);

    config()->set('agenticgraph.streaming.capture.state', 'full');
    $full = StreamNodeCompletePayloadBuilder::fromConfig($manager)->build('n', 1.0, $input, $output);
    expect($full['state']['__agenticgraph_state']['data']['b'])->toBe(['nested' => true]);

    config()->set('agenticgraph.streaming.capture.state', 'invalid');
    $fallback = StreamNodeCompletePayloadBuilder::fromConfig($manager)->build('n', 1.0, $input, $output);
    expect($fallback)->toBe(['node_name' => 'n', 'duration_ms' => 1.0]);
});
