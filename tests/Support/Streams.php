<?php

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Shahzad\AgenticGraph\Streaming\StreamDescriptor;
use Shahzad\AgenticGraph\Streaming\StreamToken;

/**
 * The SSE route URL with a valid stream token, as `$handle->stream()->streamUrl` gives it.
 *
 * @param  array<string, mixed>  $query
 */
function streamUrlFor(string $streamId, array $query = []): string
{
    $executionId = isset($query['execution_id']) ? (string) $query['execution_id'] : null;

    return route(StreamDescriptor::ROUTE_NAME, ['streamId' => $streamId] + $query + ['token' => StreamToken::issue($streamId, $executionId, 3600)]);
}

/**
 * A Pusher broadcaster whose client signs like Pusher (HMAC of "socket_id:channel"), with no pusher SDK installed.
 */
function fakePusherBroadcaster(): PusherBroadcaster
{
    return new class extends PusherBroadcaster
    {
        public function __construct() {}

        public function getPusher()
        {
            return new class
            {
                public function authorizeChannel(string $channel, string $socketId): string
                {
                    return (string) json_encode(['auth' => 'key:'.hash_hmac('sha256', "{$socketId}:{$channel}", 'secret')]);
                }
            };
        }
    };
}
