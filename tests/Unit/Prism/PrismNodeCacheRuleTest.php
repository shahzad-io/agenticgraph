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
 * Runs one text path. State: path, cache ('with' | 'without' | null), messages (for history).
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
        $llm = match ($state->get('cache')) {
            'with' => $this->llm()->withCache(10),
            'without' => $this->llm()->withoutCache(),
            default => $this->llm(),
        };

        $reply = match ($state->get('path')) {
            'prompt' => $llm->prompt('Say {x}')->vars(['x' => 'hi'])->text(),
            'prompt template' => $llm->prompt(PromptTemplate::create('Say {x}'))->vars(['x' => 'hi'])->text(),
            'chat' => $llm->chat(ChatPromptTemplate::create()->user('Say {x}'))->vars(['x' => 'hi'])->text(),
            'history' => $llm->history($state->get('messages') ?? [new UserMessage('Say hi')])->text(),
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

dataset('text paths', ['prompt', 'prompt template', 'chat', 'history']);

it('does not cache by default', function (string $path) {
    expect(providerCalls(twice(['path' => $path])))->toBe(2);
})->with(['prompt', 'prompt template', 'chat']); // history never cached before, see the next tests

it('caches when the request calls withCache, even with config off', function (string $path) {
    config()->set('agenticgraph.prism.cache_enabled', false);

    expect(providerCalls(twice(['path' => $path, 'cache' => 'with'])))->toBe(1);
})->with('text paths');

it('caches history when config is on and the request sets nothing', function () {
    config()->set('agenticgraph.prism.cache_enabled', true);

    expect(providerCalls(twice(['path' => 'history'])))->toBe(1);
});

it('guard: withoutCache wins over config on', function (string $path) {
    config()->set('agenticgraph.prism.cache_enabled', true);

    expect(providerCalls(twice(['path' => $path, 'cache' => 'without'])))->toBe(2);
})->with('text paths');

it('guard: never caches a streamed call', function (string $path) {
    expect(providerCalls(twice(['path' => $path, 'cache' => 'with']), streamed: true))->toBe(2);
})->with('text paths');

it('guard: never caches a message list with media', function () {
    $messages = [new UserMessage('Describe this', [Image::fromUrl('https://example.test/a.png')])];

    expect(providerCalls(twice(['path' => 'history', 'cache' => 'with', 'messages' => $messages])))->toBe(2);
});

it('keys the cache on max tokens and provider options', function () {
    $state = ['path' => 'prompt', 'cache' => 'with'];

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
        [new CacheRuleNode, ['path' => 'history', 'cache' => 'with', 'messages' => $conversation('approved')]],
        [new CacheRuleNode, ['path' => 'history', 'cache' => 'with', 'messages' => $conversation('approved')]],
        [new CacheRuleNode, ['path' => 'history', 'cache' => 'with', 'messages' => $conversation('denied')]],
        [new CacheRuleNode, ['path' => 'history', 'cache' => 'with', 'messages' => [new SystemMessage('Status?')]]],
        [new CacheRuleNode, ['path' => 'history', 'cache' => 'with', 'messages' => [new UserMessage('Status?')]]],
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

    providerCalls([[new CacheRuleNode, ['path' => 'history', 'messages' => $messages]]]);

    Event::assertDispatched(TokensUsed::class, function (TokensUsed $event): bool {
        return $event->prompt === "[system] Be brief.\n\n[user] Status?\n\n[tool_call] lookup {\"q\":\"refund\"}\n\n[tool_result] lookup: approved";
    });
});
