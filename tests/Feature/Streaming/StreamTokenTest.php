<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\StreamToken;

const TOKEN_STREAM = 'stream_tok_a';

class TokenNoopNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

function tokenSeed(): void
{
    $store = app(StreamStoreInterface::class);
    foreach ([[1, 'text_delta', ['delta' => 'hi']], [2, 'execution_terminal', ['status' => 'completed']]] as [$seq, $type, $payload]) {
        $store->append(new StreamEventImpl(TOKEN_STREAM, 'exec-tok', null, null, null, $type, $payload, $seq, null, new DateTimeImmutable('2024-01-01T00:00:00Z')));
    }
}

/** Calls one of the three stream routes, with the token where that route reads it. */
function tokenCall(string $route, ?string $token, string $streamId = TOKEN_STREAM): TestResponse
{
    $prefix = config('agenticgraph.streaming.routes.prefix', 'agentic-graph/streams');
    $query = $token === null ? '' : '?token='.rawurlencode($token);

    return match ($route) {
        'sse' => test()->get("/{$prefix}/{$streamId}{$query}"),
        'replay' => test()->getJson("/{$prefix}/{$streamId}/events{$query}"),
        'auth' => test()->postJson("/{$prefix}/{$streamId}/auth", array_filter([
            'socket_id' => '123.456',
            'channel_name' => 'private-agenticgraph.stream.'.$streamId,
            'token' => $token,
        ])),
    };
}

dataset('routes', ['sse', 'replay', 'auth']);

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.streaming.sse.max_duration_seconds', 1);
    app()->instance(Broadcaster::class, fakePusherBroadcaster());
    tokenSeed();
});

it('denies a request with no token', function (string $route) {
    tokenCall($route, null)->assertForbidden()->assertExactJson(['error' => 'stream_token_invalid']);
})->with('routes');

it('denies another stream\'s token', function (string $route) {
    tokenCall($route, StreamToken::issue('stream_tok_b', null, 3600))->assertForbidden()->assertExactJson(['error' => 'stream_token_invalid']);
})->with('routes');

it('denies a changed token', function (string $route) {
    $token = StreamToken::issue(TOKEN_STREAM, 'exec-tok', 3600);
    [$payload, $signature] = explode('.', $token);
    $forged = rtrim(strtr(base64_encode((string) json_encode(['s' => TOKEN_STREAM, 'e' => 'exec-other', 'x' => time() + 3600])), '+/', '-_'), '=');

    tokenCall($route, $forged.'.'.$signature)->assertForbidden()->assertExactJson(['error' => 'stream_token_invalid']);
    tokenCall($route, $payload.'.'.strrev($signature))->assertForbidden()->assertExactJson(['error' => 'stream_token_invalid']);
})->with('routes');

it('denies an expired token with its own code', function (string $route) {
    $token = StreamToken::issue(TOKEN_STREAM, null, 60);
    $this->travel(61)->seconds();

    tokenCall($route, $token)->assertForbidden()->assertExactJson(['error' => 'stream_token_expired']);
})->with('routes');

it('lets a guest with a valid token in', function (string $route) {
    expect(auth()->guest())->toBeTrue();

    tokenCall($route, StreamToken::issue(TOKEN_STREAM, null, 3600))->assertOk();
})->with('routes');

it('runs the app authorizer after the token and can deny a valid token', function (string $route) {
    $seen = [];
    AgenticGraph::authorizeStreamsUsing(function (Request $request, string $streamId, ?string $executionId) use (&$seen): bool {
        $seen = [$streamId, $executionId];

        return false;
    });

    tokenCall($route, null)->assertForbidden()->assertExactJson(['error' => 'stream_token_invalid']);
    expect($seen)->toBe([]); // the token check comes first

    tokenCall($route, StreamToken::issue(TOKEN_STREAM, 'exec-tok', 3600))->assertForbidden()->assertExactJson(['error' => 'stream_forbidden']);
    expect($seen)->toBe([TOKEN_STREAM, 'exec-tok']);
})->with('routes');

it('restores the 0.2 routes with token.required=false, and the authorizer still runs', function (string $route) {
    config()->set('agenticgraph.streaming.token.required', false);

    tokenCall($route, null)->assertOk();

    AgenticGraph::authorizeStreamsUsing(fn (): bool => false);
    tokenCall($route, null)->assertForbidden()->assertExactJson(['error' => 'stream_forbidden']);
})->with('routes');

it('signs with a key derived from the app key, never the raw app key', function () {
    $appKey = (string) config('app.key');
    $payload = rtrim(strtr(base64_encode((string) json_encode(['s' => TOKEN_STREAM, 'e' => null, 'x' => time() + 3600])), '+/', '-_'), '=');
    $sign = fn (string $key): string => rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $key, true)), '+/', '-_'), '=');

    $derived = hash_hmac('sha256', 'agenticgraph.stream-token', $appKey);

    expect(StreamToken::verify($payload.'.'.$sign($derived), TOKEN_STREAM)->isValid())->toBeTrue()
        ->and(StreamToken::verify($payload.'.'.$sign($appKey), TOKEN_STREAM)->state)->toBe('invalid');
});

it('verifies valid, invalid and expired, and carries the execution id', function () {
    $valid = StreamToken::verify(StreamToken::issue(TOKEN_STREAM, 'exec-tok', 3600), TOKEN_STREAM);
    expect($valid->state)->toBe('valid')->and($valid->executionId)->toBe('exec-tok')
        ->and(StreamToken::verify('garbage', TOKEN_STREAM)->state)->toBe('invalid')
        ->and(StreamToken::verify('', TOKEN_STREAM)->state)->toBe('invalid');

    $token = StreamToken::issue(TOKEN_STREAM, null, 10);
    $this->travel(11)->seconds();
    expect(StreamToken::verify($token, TOKEN_STREAM)->state)->toBe('expired');
});

it('reads the token from one place only: a token in the wrong place is no token', function (string $route) {
    $prefix = config('agenticgraph.streaming.routes.prefix', 'agentic-graph/streams');
    $token = StreamToken::issue(TOKEN_STREAM, null, 3600);

    $response = match ($route) {
        // SSE and replay read the query only, not a body.
        'sse' => $this->call('GET', "/{$prefix}/".TOKEN_STREAM, [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['token' => $token])),
        'replay' => $this->json('GET', "/{$prefix}/".TOKEN_STREAM.'/events', ['token' => $token]),
        // Channel auth reads the POST body only, not the query.
        'auth' => $this->postJson("/{$prefix}/".TOKEN_STREAM.'/auth?token='.rawurlencode($token), [
            'socket_id' => '123.456',
            'channel_name' => 'private-agenticgraph.stream.'.TOKEN_STREAM,
        ]),
    };

    $response->assertForbidden()->assertExactJson(['error' => 'stream_token_invalid']);
})->with('routes');

it('gives the authorizer the token\'s execution id only, never the execution_id query', function (string $route) {
    config()->set('agenticgraph.streaming.token.required', false);
    $seen = 'unset';
    AgenticGraph::authorizeStreamsUsing(function (Request $request, string $streamId, ?string $executionId) use (&$seen): bool {
        $seen = $executionId;

        return true;
    });
    $prefix = config('agenticgraph.streaming.routes.prefix', 'agentic-graph/streams');

    $response = match ($route) {
        'sse' => $this->get("/{$prefix}/".TOKEN_STREAM.'?execution_id=exec-tok'),
        'replay' => $this->getJson("/{$prefix}/".TOKEN_STREAM.'/events?execution_id=exec-tok'),
        'auth' => $this->postJson("/{$prefix}/".TOKEN_STREAM.'/auth?execution_id=exec-tok', ['socket_id' => '123.456', 'channel_name' => 'private-agenticgraph.stream.'.TOKEN_STREAM]),
    };

    $response->assertOk();
    expect($seen)->toBeNull();
})->with('routes');

it('works with no app key when token.required=false, and throws MissingAppKeyException when it is true', function () {
    AgenticGraph::register('token-no-key', GraphBuilder::create('token-no-key')
        ->addNode('stream', TokenNoopNode::class)
        ->setEntryPoint('stream')
        ->addExitPoint('stream')
        ->build());
    Bus::fake();
    $handle = AgenticGraph::execute('token-no-key', new State, new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::SSE));
    config()->set('app.key', '');
    config()->set('agenticgraph.streaming.token.required', false);

    $descriptor = $handle->stream();
    expect($descriptor->token)->toBeNull()
        ->and($descriptor->streamUrl)->not->toBeNull()->not->toContain('token=')
        ->and($descriptor->replayUrl)->not->toBeNull()
        ->and($handle->streamToken())->toBeNull();
    foreach (['sse', 'replay', 'auth'] as $route) {
        tokenCall($route, null)->assertOk();
        tokenCall($route, 'some.token')->assertOk(); // an unverifiable token is no token
    }

    config()->set('agenticgraph.streaming.token.required', true);
    expect(fn () => $handle->stream())->toThrow(MissingAppKeyException::class);
});
