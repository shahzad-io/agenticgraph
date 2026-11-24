<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Testing\TestResponse;
use Shahzad\AgenticGraph\Streaming\StreamToken;

function channelAuth(string $streamId, string $channel, string $socketId = '123.456'): TestResponse
{
    $prefix = config('agenticgraph.streaming.routes.prefix', 'agentic-graph/streams');

    return test()->postJson("/{$prefix}/{$streamId}/auth", [
        'socket_id' => $socketId,
        'channel_name' => $channel,
        'token' => StreamToken::issue($streamId, null, 3600),
    ]);
}

it('signs the stream\'s own private channel with the Pusher client', function () {
    app()->instance(Broadcaster::class, fakePusherBroadcaster());
    $channel = 'private-agenticgraph.stream.stream_ch_a';

    channelAuth('stream_ch_a', $channel)
        ->assertOk()
        ->assertExactJson(['auth' => 'key:'.hash_hmac('sha256', "123.456:{$channel}", 'secret')]);
});

it('refuses another channel with stream_channel_mismatch', function (string $channel) {
    app()->instance(Broadcaster::class, fakePusherBroadcaster());

    channelAuth('stream_ch_a', $channel)->assertForbidden()->assertExactJson(['error' => 'stream_channel_mismatch']);
})->with([
    'another stream' => 'private-agenticgraph.stream.stream_ch_b',
    'public name' => 'agenticgraph.stream.stream_ch_a',
    'presence' => 'presence-agenticgraph.stream.stream_ch_a',
]);

it('refuses a socket id Pusher would reject', function () {
    app()->instance(Broadcaster::class, fakePusherBroadcaster());

    channelAuth('stream_ch_a', 'private-agenticgraph.stream.stream_ch_a', 'bad')->assertStatus(422)->assertExactJson(['error' => 'invalid_socket_id']);
});

it('answers 501 for a broadcaster that is not Pusher or Reverb', function () {
    app()->instance(Broadcaster::class, new class implements Broadcaster
    {
        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = []) {}
    });

    channelAuth('stream_ch_a', 'private-agenticgraph.stream.stream_ch_a')->assertStatus(501)->assertExactJson(['error' => 'broadcaster_unsupported']);
});
