<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionHandle;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Http\Controllers\StreamController;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamStatus;

const FINAL_STATE_SENTINEL = 'sentinel-7f3a-do-not-leak';

class FinalStateExposureNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('response', 'visible answer');
    }
}

class FinalStateRecordingBroadcaster implements Broadcaster
{
    /** @var list<array{event: string, payload: array<string, mixed>}> */
    public array $sent = [];

    public function auth($request)
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result)
    {
        return null;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $this->sent[] = ['event' => $event, 'payload' => $payload];
    }
}

function finalStateSecretState(): State
{
    return new State([
        'secret_system_prompt' => FINAL_STATE_SENTINEL,
        'internal_customer_email' => 'secret@example.com',
    ]);
}

function finalStateGraph(): void
{
    AgenticGraph::register('final-state-exposure', GraphBuilder::create('final-state-exposure')
        ->addNode('answer', FinalStateExposureNode::class)
        ->setEntryPoint('answer')
        ->addExitPoint('answer')
        ->build());
}

function finalStateRunIterator(?array $eventTypeFilter = null): ExecutionHandle
{
    return AgenticGraph::execute('final-state-exposure', finalStateSecretState(), new ExecutionOptions(
        mode: ExecutionMode::SYNC,
        streaming: StreamingMode::ITERATOR,
        eventTypeFilter: $eventTypeFilter,
    ));
}

/**
 * @return list<StreamEvent>
 */
function finalStateStoredEvents(ExecutionHandle $handle): array
{
    return array_values(iterator_to_array(
        app(StreamStoreInterface::class)->readFrom((string) $handle->streamId(), 1, 1000),
        false
    ));
}

function finalStateTerminalPayload(ExecutionHandle $handle): array
{
    $terminals = array_values(array_filter(
        finalStateStoredEvents($handle),
        fn (StreamEvent $event) => $event->type() === StreamEventEnvelope::TYPE_EXECUTION_TERMINAL
    ));

    expect($terminals)->toHaveCount(1);

    return $terminals[0]->payload();
}

/**
 * Broadcasts a sync run through a recording broadcaster and returns the stream_close payload.
 */
function finalStateBroadcastClose(FinalStateRecordingBroadcaster $broadcaster): array
{
    app()->instance(Broadcaster::class, $broadcaster);
    AgenticGraph::broadcast('final-state-exposure', finalStateSecretState(), async: false);

    $closes = array_values(array_filter(
        $broadcaster->sent,
        fn (array $sent) => $sent['event'] === StreamEventEnvelope::TYPE_STREAM_CLOSE
    ));

    expect($closes)->toHaveCount(1);

    return $closes[0]['payload'];
}

/**
 * Requests the SSE route with a cursor past the end of an empty stream, so the
 * controller answers with stream_replay_unavailable for the given execution.
 */
function finalStateReplayUnavailablePayload(string $executionId): array
{
    config()->set('agenticgraph.streaming.sse.max_duration_seconds', 2);
    config()->set('agenticgraph.streaming.sse.poll_interval_ms', 50);
    config()->set('agenticgraph.streaming.sse.heartbeat_interval', 0);

    $streamId = (string) Str::uuid();
    $request = Request::create('/streams/'.$streamId, 'GET', ['cursor' => 10, 'execution_id' => $executionId]);
    $response = (new StreamController)->stream($streamId, $request, app(StreamStoreInterface::class));

    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();

    expect($content)->toContain('event: stream_replay_unavailable');
    preg_match('/event: stream_replay_unavailable\ndata: (.+)\n/', $content, $match);

    return json_decode($match[1], true)['payload'];
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    finalStateGraph();
});

it('sends null final_state in all three client paths when expose is false', function () {
    config()->set('agenticgraph.streaming.final_state', ['expose' => false, 'keys' => null]);

    $handle = finalStateRunIterator();

    expect(finalStateTerminalPayload($handle))->toHaveKey('final_state')
        ->and(finalStateTerminalPayload($handle)['final_state'])->toBeNull()
        ->and(finalStateBroadcastClose(new FinalStateRecordingBroadcaster)['meta'])->toHaveKey('final_state')
        ->and(finalStateBroadcastClose(new FinalStateRecordingBroadcaster)['meta']['final_state'])->toBeNull()
        ->and(finalStateReplayUnavailablePayload($handle->id()))->toHaveKey('final_state')
        ->and(finalStateReplayUnavailablePayload($handle->id())['final_state'])->toBeNull();
});

it('defaults to null final_state when the config key is missing', function () {
    $streaming = config('agenticgraph.streaming');
    unset($streaming['final_state']);
    config()->set('agenticgraph.streaming', $streaming);

    $handle = finalStateRunIterator();

    expect(config()->has('agenticgraph.streaming.final_state'))->toBeFalse()
        ->and(finalStateTerminalPayload($handle)['final_state'])->toBeNull()
        ->and(finalStateBroadcastClose(new FinalStateRecordingBroadcaster)['meta']['final_state'])->toBeNull()
        ->and(finalStateReplayUnavailablePayload($handle->id())['final_state'])->toBeNull();
});

it('sends only allowlisted top-level keys when expose is true with keys', function () {
    config()->set('agenticgraph.streaming.final_state', ['expose' => true, 'keys' => ['response']]);

    $handle = finalStateRunIterator();
    $expected = ['response' => 'visible answer'];

    expect(finalStateTerminalPayload($handle)['final_state'])->toBe($expected)
        ->and(finalStateBroadcastClose(new FinalStateRecordingBroadcaster)['meta']['final_state'])->toBe($expected)
        ->and(finalStateReplayUnavailablePayload($handle->id())['final_state'])->toBe($expected);
});

it('sends the full plain state when expose is true and keys is null', function () {
    config()->set('agenticgraph.streaming.final_state', ['expose' => true, 'keys' => null]);

    $handle = finalStateRunIterator();
    $expected = [
        'secret_system_prompt' => FINAL_STATE_SENTINEL,
        'internal_customer_email' => 'secret@example.com',
        'response' => 'visible answer',
    ];

    expect(finalStateTerminalPayload($handle)['final_state'])->toBe($expected)
        ->and(finalStateBroadcastClose(new FinalStateRecordingBroadcaster)['meta']['final_state'])->toBe($expected)
        ->and(finalStateReplayUnavailablePayload($handle->id())['final_state'])->toBe($expected);
});

it('keeps other stream_close meta keys unchanged', function () {
    $payload = StreamEventEnvelope::streamCloseWirePayload(
        StreamStatus::CANCELLED,
        ['reason' => 'Needs approval', 'checkpoint_id' => 'cp-1', 'final_state' => ['secret' => FINAL_STATE_SENTINEL]]
    );

    expect($payload)->toBe([
        'status' => 'cancelled',
        'meta' => ['reason' => 'Needs approval', 'checkpoint_id' => 'cp-1', 'final_state' => null],
    ]);
});

it('emits execution_terminal even when eventTypeFilter excludes it', function () {
    $handle = finalStateRunIterator(eventTypeFilter: ['text_delta']);

    $types = array_map(fn (StreamEvent $event) => $event->type(), finalStateStoredEvents($handle));

    expect($types)->toBe([StreamEventEnvelope::TYPE_EXECUTION_TERMINAL])
        ->and(finalStateTerminalPayload($handle)['status'])->toBe('completed');
});

it('puts no state value into any stored or broadcast client payload by default', function () {
    $handle = finalStateRunIterator();
    $broadcaster = new FinalStateRecordingBroadcaster;
    finalStateBroadcastClose($broadcaster);

    $stored = array_map(fn (StreamEvent $event) => [
        'type' => $event->type(),
        'node_name' => $event->nodeName(),
        'payload' => $event->payload(),
    ], finalStateStoredEvents($handle));

    expect($stored)->not->toBeEmpty()
        ->and($broadcaster->sent)->not->toBeEmpty()
        ->and(json_encode($stored))->not->toContain(FINAL_STATE_SENTINEL)
        ->and(json_encode($broadcaster->sent))->not->toContain(FINAL_STATE_SENTINEL)
        ->and(json_encode(finalStateReplayUnavailablePayload($handle->id())))->not->toContain(FINAL_STATE_SENTINEL);
});

it('reads the keys allowlist from AGENTICGRAPH_STREAM_FINAL_STATE_KEYS', function (?string $env, ?array $expected) {
    $env === null ? putenv('AGENTICGRAPH_STREAM_FINAL_STATE_KEYS') : putenv("AGENTICGRAPH_STREAM_FINAL_STATE_KEYS={$env}");

    try {
        $config = require __DIR__.'/../../../config/agenticgraph.php';
    } finally {
        putenv('AGENTICGRAPH_STREAM_FINAL_STATE_KEYS');
    }

    expect($config['streaming']['final_state']['keys'])->toBe($expected);
})->with([
    'unset' => [null, null],
    'empty' => ['', null],
    'only commas and spaces' => [' , ,', null],
    'one key' => ['response', ['response']],
    'trimmed list' => [' response , status ,', ['response', 'status']],
]);
