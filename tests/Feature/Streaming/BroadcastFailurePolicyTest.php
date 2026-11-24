<?php

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster;
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
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Testing\Llm;

class BroadcastPolicyToolNode extends PrismNode
{
    protected ?string $model = 'openai:test-model';

    public function execute(StateContract $state): StateContract
    {
        $tool = (new Tool)->as('lookup')->for('lookup')->withStringParameter('q', 'q')
            ->using(fn (string $q): string => str_repeat('x', 12_000));
        $text = $this->llm()->prompt('a')->tools([$tool])->maxSteps(2)->run()->text;

        return $state->set('answer', $text);
    }
}

/**
 * Reverb as the Pusher broadcaster sees it: a request body over 10,000 bytes is refused, and when the server is down
 * every call fails.
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
            if (strlen((string) $body) > 10_000) {
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

it('fails the run as before with streaming.broadcast.fatal', function () {
    config()->set('agenticgraph.streaming.broadcast.fatal', true);

    expect(fn () => runBroadcastPolicyGraph(reverbLikeBroadcaster(down: true)))
        ->toThrow(BroadcastException::class, 'Failed to connect');
});
