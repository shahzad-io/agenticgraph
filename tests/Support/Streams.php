<?php

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Pusher\ApiErrorException;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\RetentionPolicyFactory;
use Shahzad\AgenticGraph\Streaming\StreamDescriptor;
use Shahzad\AgenticGraph\Streaming\StreamErrorHandler;
use Shahzad\AgenticGraph\Streaming\StreamObserverFactory;
use Shahzad\AgenticGraph\Streaming\StreamSinkFactory;
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

/**
 * A broadcaster that records every call (channel, event, payload). Pass one to useStreamBroadcaster() to check that a
 * path sends through StreamSinkFactory::broadcastSink().
 */
function frameRecorder(): Broadcaster
{
    return new class implements Broadcaster
    {
        /** @var list<array{channel: string, event: string, payload: array<string, mixed>}> */
        public array $frames = [];

        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = [])
        {
            $this->frames[] = ['channel' => (string) $channels[0], 'event' => (string) $event, 'payload' => $payload];
        }
    };
}

/**
 * Binds the app broadcaster to $app and gives StreamSinkFactory its own $stream broadcaster, as the factory's short
 * timeout connection is a separate client. A frame on $app means a path did not use broadcastSink().
 */
function useStreamBroadcaster(Broadcaster $app, Broadcaster $stream): void
{
    app()->instance(Broadcaster::class, $app);
    app()->bind(StreamSinkFactory::class, fn ($container) => new StreamSinkFactory(
        $stream,
        $container->make(StreamStoreInterface::class),
        false,
        100,
        false,
        new RetentionPolicyFactory,
        new StreamObserverFactory,
        $container->make(StreamErrorHandler::class),
    ));
}

/**
 * The events a client gets from recorded frames, with `batch` frames unpacked: list of [type, wire body].
 *
 * @param  list<array{event: string, payload: array<string, mixed>}>  $frames
 * @return list<array{0: string, 1: array<string, mixed>}>
 */
function unpackFrames(array $frames): array
{
    $events = [];
    foreach ($frames as $frame) {
        if ($frame['event'] !== 'batch') {
            $events[] = [$frame['event'], $frame['payload']];

            continue;
        }
        foreach ($frame['payload']['events'] as $inner) {
            $type = $inner['type'];
            unset($inner['type']);
            $events[] = [$type, $inner];
        }
    }

    return $events;
}

/**
 * A Pusher broadcaster on a fake Pusher client. trigger() records the request body as the Pusher SDK builds it
 * (make_trigger_body), or throws Pusher\ApiErrorException with the code $codes sets for that event name.
 *
 * @param  array<string, int>  $codes  event name => code (0: network error, else the HTTP status)
 */
function fakePusherClientBroadcaster(array $codes = []): PusherBroadcaster
{
    return new class($codes) extends PusherBroadcaster
    {
        public function __construct(array $codes)
        {
            $this->pusher = new class($codes)
            {
                /** @var list<string> request bodies sent */
                public array $bodies = [];

                /** @var list<string> event names sent */
                public array $sent = [];

                public int $calls = 0;

                public function __construct(private readonly array $codes) {}

                public function trigger($channels, string $event, $data, array $params = []): object
                {
                    $this->calls++;
                    if (isset($this->codes[$event])) {
                        throw new ApiErrorException('refused', $this->codes[$event]);
                    }
                    $channels = array_values((array) $channels);
                    $body = ['name' => $event, 'data' => json_encode($data, JSON_THROW_ON_ERROR)]
                        + (count($channels) === 1 ? ['channel' => $channels[0]] : ['channels' => $channels]);
                    $this->bodies[] = json_encode(array_merge($body, $params), JSON_THROW_ON_ERROR);
                    $this->sent[] = $event;

                    return new stdClass;
                }
            };
        }
    };
}
