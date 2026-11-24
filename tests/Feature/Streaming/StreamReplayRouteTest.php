<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Http\Controllers\StreamController;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\StreamToken;
use Shahzad\AgenticGraph\Testing\Llm;

class ReplayAnswerNode extends PrismNode
{
    protected ?string $model = 'openai:test-model';

    public function execute(StateContract $state): StateContract
    {
        return $state->set('answer', $this->llm()->prompt('a')->text());
    }
}

/** Records broadcast calls; the replay route reads the store, so the frames are not checked here. */
function replayBroadcaster(): Broadcaster
{
    return new class implements Broadcaster
    {
        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = []) {}
    };
}

function replayGraph(string $answer): void
{
    Llm::fake([$answer]);
    AgenticGraph::register('replay', GraphBuilder::create('replay')
        ->addNode('agent', ReplayAnswerNode::class)
        ->setEntryPoint('agent')
        ->addExitPoint('agent')
        ->build());
}

/** @param  list<int>  $seqs */
function replaySeed(string $streamId, array $seqs): void
{
    $store = app(StreamStoreInterface::class);
    foreach ($seqs as $seq) {
        $store->append(new StreamEventImpl($streamId, 'exec-replay', 'agent', null, null, 'text_delta', ['delta' => "w{$seq}"], $seq, null, new DateTimeImmutable('2024-01-01T00:00:00Z')));
    }
}

function replayPath(string $streamId, array $query = [], ?string $executionId = null): string
{
    $prefix = config('agenticgraph.streaming.routes.prefix', 'agentic-graph/streams');
    $query['token'] = StreamToken::issue($streamId, $executionId, 3600);

    return "/{$prefix}/{$streamId}/events?".http_build_query($query);
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
});

// Copied from ~/ag2-scratch/broadcast-0.3/core/BroadcastGapsTest.php, "gap 2/3". The route now needs the token.
it('gap 2/3: a JSON replay route returns the stored events after a cursor', function () {
    app()->instance(Broadcaster::class, replayBroadcaster());
    replayGraph('hello world');
    $handle = AgenticGraph::broadcast('replay', new State, async: false);
    $prefix = config('agenticgraph.streaming.routes.prefix', 'agentic-graph/streams');

    // The route exists and asks for a token (on 0.2.12: 404).
    $this->getJson("/{$prefix}/{$handle->streamId()}/events?after=0")->assertForbidden();

    $response = $this->getJson("/{$prefix}/{$handle->streamId()}/events?after=0&token=".$handle->streamToken());

    $response->assertOk();
    $stored = iterator_to_array(app(StreamStoreInterface::class)->readFrom((string) $handle->streamId(), 1, 5000), false);
    expect(array_column($response->json('events'), 'type'))->toBe(array_map(fn ($e) => $e->type(), $stored))
        ->and($response->json('last_seq'))->toBe(end($stored)->seq());
});

it('reads from the start without after, with the nine wire keys in each body', function () {
    replaySeed('stream_r1', [1, 2, 3, 4, 5]);

    $response = $this->getJson(replayPath('stream_r1'))->assertOk();

    expect($response->json('events'))->toHaveCount(5)
        ->and($response->json('events.0.type'))->toBe('text_delta')
        ->and(array_keys($response->json('events.0.body')))->toBe(['stream_id', 'execution_id', 'node_name', 'span_id', 'parent_span_id', 'seq', 'node_seq', 'occurred_at', 'payload'])
        ->and(array_column(array_column($response->json('events'), 'body'), 'seq'))->toBe([1, 2, 3, 4, 5])
        ->and($response->json('last_seq'))->toBe(5)
        ->and($response->json('more'))->toBeFalse()
        ->and($response->json('gone'))->toBeFalse();
});

it('reads after a cursor', function () {
    replaySeed('stream_r2', [1, 2, 3, 4, 5]);

    $response = $this->getJson(replayPath('stream_r2', ['after' => 3]))->assertOk();

    expect(array_column(array_column($response->json('events'), 'body'), 'seq'))->toBe([4, 5])
        ->and($response->json('last_seq'))->toBe(5);
});

it('answers gone with no events when the cursor is below the first stored seq', function () {
    replaySeed('stream_r3', [1, 2, 3, 4, 5, 6]);
    app(StreamStoreInterface::class)->truncate('stream_r3', 4);

    $response = $this->getJson(replayPath('stream_r3', ['after' => 1]))->assertOk();

    expect($response->json('gone'))->toBeTrue()
        ->and($response->json('events'))->toBe([])
        ->and($response->json('more'))->toBeFalse();
});

it('gives last_seq past a hole on a filtered stream', function () {
    app()->instance(Broadcaster::class, replayBroadcaster());
    replayGraph('hello world');
    $handle = AgenticGraph::broadcast('replay', new State, events: ['text_delta'], async: false);
    $stored = iterator_to_array(app(StreamStoreInterface::class)->readFrom((string) $handle->streamId(), 1, 5000), false);
    $seqs = array_map(fn ($e) => $e->seq(), $stored);
    expect($seqs)->not->toBe(range($seqs[0], end($seqs))); // the filter left holes

    $response = $this->getJson(replayPath((string) $handle->streamId(), ['after' => $seqs[0]]))->assertOk();

    expect(array_column(array_column($response->json('events'), 'body'), 'seq'))->toBe(array_slice($seqs, 1))
        ->and($response->json('last_seq'))->toBe(end($seqs))
        ->and($response->json('events.'.(count($seqs) - 2).'.type'))->toBe('execution_terminal');
});

it('pages by 200 with more, and the client asks again with after=last_seq', function () {
    replaySeed('stream_r4', range(1, 250));

    $first = $this->getJson(replayPath('stream_r4'))->assertOk();
    expect($first->json('events'))->toHaveCount(200)
        ->and($first->json('more'))->toBeTrue()
        ->and($first->json('last_seq'))->toBe(200);

    $second = $this->getJson(replayPath('stream_r4', ['after' => $first->json('last_seq')]))->assertOk();
    expect($second->json('events'))->toHaveCount(50)
        ->and($second->json('more'))->toBeFalse()
        ->and($second->json('last_seq'))->toBe(250);
});

it('sends final_state on gone only when the execution owns the stream', function () {
    config()->set('agenticgraph.streaming.final_state.expose', true);
    app()->instance(Broadcaster::class, replayBroadcaster());
    replayGraph('hello world');
    $handle = AgenticGraph::broadcast('replay', new State, async: false);
    $streamId = (string) $handle->streamId();
    $store = app(StreamStoreInterface::class);
    $store->truncate($streamId, (int) $store->lastSeq($streamId) + 1);

    $owned = $this->getJson(replayPath($streamId, ['after' => 10], $handle->id()))->assertOk();
    expect($owned->json('gone'))->toBeTrue()
        ->and($owned->json('final_state.answer'))->toBe('hello world');

    // A token for another stream, with this run's execution id: the run does not own that stream.
    $other = $this->getJson(replayPath('stream_other', ['after' => 10], $handle->id()))->assertOk();
    expect($other->json('gone'))->toBeTrue()
        ->and($other->json())->not->toHaveKey('final_state');
});

it('applies the route middleware to the replay and auth routes', function () {
    $middleware = config('agenticgraph.streaming.routes.middleware');

    expect(Route::getRoutes()->getByName('agenticgraph.streams.events')?->gatherMiddleware())->toBe($middleware)
        ->and(Route::getRoutes()->getByName('agenticgraph.streams.auth')?->gatherMiddleware())->toBe($middleware);
});

it('reads a filtered stream from the start with after=0 or cursor=0, and a cursor above 0 below the first seq stays gone', function () {
    app()->instance(Broadcaster::class, replayBroadcaster());
    replayGraph('hello world');
    $handle = AgenticGraph::broadcast('replay', new State, events: ['text_delta'], async: false);
    $streamId = (string) $handle->streamId();
    $first = app(StreamStoreInterface::class)->firstSeq($streamId);
    expect($first)->toBeGreaterThan(2); // the filter dropped the first events
    $sse = function (array $query) use ($streamId): string {
        config()->set('agenticgraph.streaming.sse.max_duration_seconds', 1);
        ob_start();
        (new StreamController)->stream($streamId, Request::create(streamUrlFor($streamId, $query)), app(StreamStoreInterface::class))->sendContent();

        return (string) ob_get_clean();
    };

    $replay = $this->getJson(replayPath($streamId, ['after' => 0]))->assertOk();
    expect($replay->json('gone'))->toBeFalse()
        ->and($replay->json('events.0.body.seq'))->toBe($first)
        ->and($sse(['cursor' => 0]))->toContain("id: {$first}\n")->not->toContain('stream_replay_unavailable');

    expect($this->getJson(replayPath($streamId, ['after' => 1]))->json('gone'))->toBeTrue()
        ->and($sse(['cursor' => 1]))->toContain('stream_replay_unavailable');
});
