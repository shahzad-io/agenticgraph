<?php

use Illuminate\Support\Facades\Event;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\PromptTemplate;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;

/**
 * Calls one text helper. State: helper, cache ('with' | 'without' | null), messages (for promptMessages).
 */
class CacheRuleNode extends PrismNode
{
    public function __construct(?int $maxTokens = null, array $providerOptions = [])
    {
        $this->maxTokens = $maxTokens;
        $this->providerOptions = $providerOptions;
    }

    public function execute(StateContract $state): StateContract
    {
        match ($state->get('cache')) {
            'with' => $this->withCache(10),
            'without' => $this->withoutCache(),
            default => null,
        };

        $reply = match ($state->get('helper')) {
            'prompt' => $this->prompt('Say {x}', ['x' => 'hi']),
            'promptTemplate' => $this->promptTemplate(PromptTemplate::create('Say {x}'), ['x' => 'hi']),
            'chatPrompt' => $this->chatPrompt(ChatPromptTemplate::create()->user('Say {x}'), ['x' => 'hi']),
            'promptMessages' => $this->promptMessages($state->get('messages') ?? [new UserMessage('Say hi')]),
        };

        return $state->set('reply', $reply);
    }
}

/**
 * Runs each [node, state] pair in order and returns the number of provider requests.
 *
 * @param  list<array{0: CacheRuleNode, 1: array<string, mixed>}>  $runs
 */
function providerCalls(array $runs, bool $streamed = false): int
{
    $fake = Prism::fake(array_fill(0, count($runs), TextResponseFake::make()->withText('ok')->withUsage(new Usage(5, 1))));

    app()->forgetInstance(StreamContext::class);
    if ($streamed) {
        app()->instance(StreamContext::class, new StreamContext(
            new StreamSession('stream-cache', 'exec-cache', new StreamOptions, time()),
            new MemorySink
        ));
    }

    try {
        foreach ($runs as [$node, $state]) {
            $node->execute(new State($state));
        }
    } finally {
        app()->forgetInstance(StreamContext::class);
    }

    $count = 0;
    $fake->assertRequest(function (array $recorded) use (&$count): void {
        $count = count($recorded);
    });

    return $count;
}

function twice(array $state, ?CacheRuleNode $node = null): array
{
    return [[$node ?? new CacheRuleNode, $state], [$node ?? new CacheRuleNode, $state]];
}

dataset('text helpers', ['prompt', 'promptTemplate', 'chatPrompt', 'promptMessages']);

it('does not cache by default', function (string $helper) {
    expect(providerCalls(twice(['helper' => $helper])))->toBe(2);
})->with(['prompt', 'promptTemplate', 'chatPrompt']); // promptMessages never cached before, see the next tests

it('caches when the node calls withCache, even with config off', function (string $helper) {
    config()->set('agenticgraph.prism.cache_enabled', false);

    expect(providerCalls(twice(['helper' => $helper, 'cache' => 'with'])))->toBe(1);
})->with('text helpers');

it('caches promptMessages when config is on and the node sets nothing', function () {
    config()->set('agenticgraph.prism.cache_enabled', true);

    expect(providerCalls(twice(['helper' => 'promptMessages'])))->toBe(1);
});

it('guard: withoutCache wins over config on', function (string $helper) {
    config()->set('agenticgraph.prism.cache_enabled', true);

    expect(providerCalls(twice(['helper' => $helper, 'cache' => 'without'])))->toBe(2);
})->with('text helpers');

it('guard: never caches a streamed call', function (string $helper) {
    expect(providerCalls(twice(['helper' => $helper, 'cache' => 'with']), streamed: true))->toBe(2);
})->with('text helpers');

it('guard: never caches a message list with media', function () {
    $messages = [new UserMessage('Describe this', [Image::fromUrl('https://example.test/a.png')])];

    expect(providerCalls(twice(['helper' => 'promptMessages', 'cache' => 'with', 'messages' => $messages])))->toBe(2);
});

it('keys the cache on max tokens and provider options', function () {
    $state = ['helper' => 'prompt', 'cache' => 'with'];

    expect(providerCalls([
        [new CacheRuleNode(maxTokens: 20), $state],
        [new CacheRuleNode(maxTokens: 20), $state],
        [new CacheRuleNode(maxTokens: 40), $state],
        [new CacheRuleNode(providerOptions: ['use_tool_calling' => true]), $state],
    ]))->toBe(3);
});

it('keys the cache on the full message list, tool calls and results included', function () {
    $call = new ToolCall('call_1', 'lookup', ['q' => 'refund']);
    $conversation = fn (string $result): array => [
        new SystemMessage('Be brief.'),
        new UserMessage('Status?'),
        new AssistantMessage('', [$call]),
        new ToolResultMessage([new ToolResult('call_1', 'lookup', ['q' => 'refund'], $result)]),
    ];

    expect(providerCalls([
        [new CacheRuleNode, ['helper' => 'promptMessages', 'cache' => 'with', 'messages' => $conversation('approved')]],
        [new CacheRuleNode, ['helper' => 'promptMessages', 'cache' => 'with', 'messages' => $conversation('approved')]],
        [new CacheRuleNode, ['helper' => 'promptMessages', 'cache' => 'with', 'messages' => $conversation('denied')]],
        [new CacheRuleNode, ['helper' => 'promptMessages', 'cache' => 'with', 'messages' => [new SystemMessage('Status?')]]],
        [new CacheRuleNode, ['helper' => 'promptMessages', 'cache' => 'with', 'messages' => [new UserMessage('Status?')]]],
    ]))->toBe(4);
});

it('logs roles and tool messages in the TokensUsed prompt', function () {
    config()->set('agenticgraph.tracing.capture.prompts', true);
    Event::fake([TokensUsed::class]);
    $messages = [
        new SystemMessage('Be brief.'),
        new UserMessage('Status?'),
        new AssistantMessage('', [new ToolCall('call_1', 'lookup', ['q' => 'refund'])]),
        new ToolResultMessage([new ToolResult('call_1', 'lookup', ['q' => 'refund'], 'approved')]),
    ];

    providerCalls([[new CacheRuleNode, ['helper' => 'promptMessages', 'messages' => $messages]]]);

    Event::assertDispatched(TokensUsed::class, function (TokensUsed $event): bool {
        return $event->prompt === "[system] Be brief.\n\n[user] Status?\n\n[tool_call] lookup {\"q\":\"refund\"}\n\n[tool_result] lookup: approved";
    });
});
