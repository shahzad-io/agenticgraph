<?php

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Streaming\StreamStatus;
use Shahzad\AgenticGraph\Testing\Llm;

class BroadcastPolicyToolNode extends PrismNode
{
    public static int $resultBytes = 12_000;

    protected ?string $model = 'openai:test-model';

    public function execute(StateContract $state): StateContract
    {
        $tool = (new Tool)->as('lookup')->for('lookup')->withStringParameter('q', 'q')
            ->using(fn (string $q): string => str_repeat('x', self::$resultBytes));
        $text = $this->llm()->prompt('a')->tools([$tool])->maxSteps(2)->run()->text;

        return $state->set('answer', $text);
    }
}

/**
 * Reverb as the Pusher broadcaster sees it: a request over 10,000 bytes is refused (Reverb counts the request line and
 * headers too: 361 bytes measured with the Pusher SDK), and when the server is down every call fails.
 */
function reverbLikeBroadcaster(bool $down): Broadcaster
{
    return new class($down) implements Broadcaster
    {
        /** @var list<string> */
        public array $sent = [];

        public int $calls = 0;

        public function __construct(private readonly bool $down) {}

        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = [])
        {
            $this->calls++;
            if ($this->down) {
                throw new BroadcastException('Pusher error: cURL error 7: Failed to connect to reverb port 8080.');
            }
            $body = json_encode(['name' => $event, 'data' => json_encode($payload), 'channels' => $channels]);
            if (strlen((string) $body) + 361 > 10_000) {
                throw new BroadcastException('Pusher error: Payload too large.');
            }
            $this->sent[] = $event;
        }
    };
}

function runBroadcastPolicyGraph(Broadcaster $broadcaster): array
{
    app()->instance(Broadcaster::class, $broadcaster);
    Llm::fake([
        TextResponseFake::make()->withToolCalls([new ToolCall('c0', 'lookup', ['q' => 'a'])])->withFinishReason(FinishReason::ToolCalls),
        'done, all done.', // three deltas: one batch frame
    ]);
    AgenticGraph::register('broadcast-policy', GraphBuilder::create('broadcast-policy')
        ->addNode('agent', BroadcastPolicyToolNode::class)
        ->setEntryPoint('agent')
        ->addExitPoint('agent')
        ->build());

    $handle = AgenticGraph::broadcast('broadcast-policy', new State, async: false);
    $stored = iterator_to_array(app(StreamStoreInterface::class)->readFrom((string) $handle->streamId(), 1, 500), false);

    return [$handle, array_map(fn ($e) => $e->type(), $stored), end($stored)];
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    BroadcastPolicyToolNode::$resultBytes = 12_000;
});

it('completes the run when Reverb is down, tries once and writes the store', function () {
    $broadcaster = reverbLikeBroadcaster(down: true);

    [$handle, $types, $terminal] = runBroadcastPolicyGraph($broadcaster);

    expect($handle->status()->value)->toBe('completed')
        ->and($broadcaster->calls)->toBe(1)
        ->and($types)->toContain('tool_result')
        ->and($terminal->type())->toBe(StreamEventEnvelope::TYPE_EXECUTION_TERMINAL)
        ->and($terminal->payload()['status'])->toBe('completed');
});

it('completes the run with a tool result over 10 KB: the frame is skipped, the rest is sent', function () {
    $broadcaster = reverbLikeBroadcaster(down: false);

    [$handle, $types, $terminal] = runBroadcastPolicyGraph($broadcaster);

    expect($handle->status()->value)->toBe('completed')
        ->and($types)->toContain('tool_result')
        ->and($terminal->payload()['status'])->toBe('completed')
        ->and($broadcaster->sent)->not->toContain('tool_result')
        ->and($broadcaster->sent)->toContain('tool_call', 'batch', StreamEventEnvelope::TYPE_EXECUTION_TERMINAL, StreamEventEnvelope::TYPE_STREAM_CLOSE);
});

it('skips a single frame with a body under max_bytes but over the header headroom, and sends the rest', function () {
    BroadcastPolicyToolNode::$resultBytes = 9_200; // a tool_result body of 9,778 bytes: under max_bytes, refused with the headers
    $broadcaster = reverbLikeBroadcaster(down: false);

    [$handle, $types, $terminal] = runBroadcastPolicyGraph($broadcaster);

    expect($handle->status()->value)->toBe('completed')
        ->and($types)->toContain('tool_result')
        ->and($broadcaster->sent)->not->toContain('tool_result')
        ->and($broadcaster->sent)->toContain('batch', StreamEventEnvelope::TYPE_EXECUTION_TERMINAL, StreamEventEnvelope::TYPE_STREAM_CLOSE);
});

it('fails the run as before with streaming.broadcast.fatal', function () {
    config()->set('agenticgraph.streaming.broadcast.fatal', true);

    expect(fn () => runBroadcastPolicyGraph(reverbLikeBroadcaster(down: true)))
        ->toThrow(BroadcastException::class, 'Failed to connect');
});

it('skips a frame Pusher or Reverb refuses with a 4xx code, and sends every later frame and the terminal', function () {
    BroadcastPolicyToolNode::$resultBytes = 100; // under the body limit: only the server refuses it
    Log::spy();
    $broadcaster = fakePusherClientBroadcaster(['tool_result' => 413]);

    [$handle, $types] = runBroadcastPolicyGraph($broadcaster);
    $client = $broadcaster->getPusher();

    expect($handle->status()->value)->toBe('completed')
        ->and($types)->toContain('tool_result')
        ->and($client->sent)->not->toContain('tool_result')
        ->and(array_values(array_diff(array_slice($client->sent, (int) array_search('tool_call', $client->sent, true) + 1), ['batch'])))
        ->toBe([...array_values(array_diff(array_slice($types, (int) array_search('tool_result', $types, true) + 1), ['text_delta'])), StreamEventEnvelope::TYPE_STREAM_CLOSE])
        ->and($client->sent)->toContain('batch');
    Log::shouldHaveReceived('warning')->with('agenticgraph.broadcast.frame_skipped', Mockery::on(fn (array $c) => $c['code'] === 413 && $c['event'] === 'tool_result'));
});

it('stops the sink on Pusher code 0 or 5xx, and the run completes', function (int $code) {
    BroadcastPolicyToolNode::$resultBytes = 100;
    $broadcaster = fakePusherClientBroadcaster(['tool_result' => $code]);

    [$handle, $types, $terminal] = runBroadcastPolicyGraph($broadcaster);
    $client = $broadcaster->getPusher();

    expect($handle->status()->value)->toBe('completed')
        ->and($terminal->type())->toBe(StreamEventEnvelope::TYPE_EXECUTION_TERMINAL)
        ->and($client->sent[count($client->sent) - 1])->toBe('tool_call')
        ->and($client->calls)->toBe(count($client->sent) + 1); // nothing tried after the failed frame
})->with([0, 503]);

it('sends the same request body as Laravel broadcast(), for a normal frame and a batch', function () {
    config()->set('agenticgraph.streaming.broadcast.batch_ms', 60_000);
    $event = fn (int $seq, string $type) => new StreamEventImpl('stream_batch', 'exec_batch', 'agent', null, null, $type, ['delta' => "d$seq"], $seq, $seq, new DateTimeImmutable('2024-01-01T00:00:00Z'));
    $events = [$event(1, 'text_delta'), $event(2, 'text_delta'), $event(3, 'thinking_delta'), $event(4, 'node_complete')];
    $bodies = function (bool $laravel) use ($events): array {
        config()->set('agenticgraph.streaming.broadcast.fatal', $laravel); // fatal sends through PusherBroadcaster::broadcast()
        $broadcaster = fakePusherClientBroadcaster();
        $sink = new BroadcastSink($broadcaster, 'stream_batch');
        foreach ($events as $event) {
            $sink->emit($event);
        }
        $sink->close(StreamStatus::SUCCESS);

        return $broadcaster->getPusher()->bodies;
    };

    $viaLaravel = $bodies(true);
    $direct = $bodies(false);

    expect(array_map(fn ($b) => json_decode($b, true)['name'], $direct))->toBe(['batch', 'node_complete', 'stream_close'])
        ->and($direct)->toBe($viaLaravel);
});
