<?php

use Illuminate\Support\Facades\Event;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Events\ToolCallCaptured;
use Shahzad\AgenticGraph\Llm\LlmRequest;
use Shahzad\AgenticGraph\Llm\LlmResponse;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;
use Shahzad\AgenticGraph\Prompts\MessageSerializer;
use Shahzad\AgenticGraph\Prompts\PromptTemplate;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Tracing\TraceContext;

/** Visitor text that must reach the model byte for byte. */
const LLM_BRACES = 'Hi {name}, literal {{x}}, stray { and } here';

/**
 * Hands $this->llm() to a closure. Node settings come from the constructor.
 */
class LlmProbeNode extends PrismNode
{
    public function __construct(?string $model = null, ?int $maxTokens = null, array $providerOptions = [], ?bool $cacheEnabled = null)
    {
        $this->model = $model;
        $this->maxTokens = $maxTokens;
        $this->providerOptions = $providerOptions;
        $this->cacheEnabled = $cacheEnabled;
    }

    public function execute(StateContract $state): StateContract
    {
        return $state;
    }

    public function call(Closure $build): mixed
    {
        return $build($this->llm());
    }
}

function llmText(string $text = 'ok'): TextResponseFake
{
    return TextResponseFake::make()->withText($text)->withUsage(new Usage(5, 1));
}

function llmStructured(array $data = ['label' => 'ok']): StructuredResponseFake
{
    return StructuredResponseFake::make()->withStructured($data)->withText(json_encode($data))->withUsage(new Usage(5, 1));
}

function llmTwoSteps(): TextResponseFake
{
    return llmText('Final.')->withUsage(new Usage(30, 7))->withSteps(collect([
        TextStepFake::make()->withText('Checking.')->withFinishReason(FinishReason::ToolCalls)->withUsage(new Usage(10, 2))
            ->withToolCalls([new ToolCall('c1', 'lookup', ['q' => 'a'])])->withToolResults([new ToolResult('c1', 'lookup', ['q' => 'a'], 'found a')]),
        TextStepFake::make()->withText('Final.')->withUsage(new Usage(20, 5)),
    ]));
}

function llmSchema(string $field = 'label'): ObjectSchema
{
    return new ObjectSchema('answer', 'answer', [new StringSchema($field, $field)], [$field]);
}

function llmTool(string $name = 'lookup'): Tool
{
    return (new Tool)->as($name)->for('Look up a value')->withStringParameter('q', 'query')->using(fn (string $q): string => "found {$q}");
}

/**
 * Runs each closure on a fresh probe node (or the given one) and returns every recorded Prism request.
 *
 * @param  list<Closure>  $builds
 * @return list<mixed>
 */
function llmRecorded(array $builds, array $fakes, ?LlmProbeNode $node = null): array
{
    $fake = Prism::fake($fakes);
    foreach ($builds as $build) {
        ($node ?? new LlmProbeNode)->call($build);
    }

    $requests = [];
    $fake->assertRequest(function (array $recorded) use (&$requests): void {
        $requests = $recorded;
    });

    return $requests;
}

function llmBindSink(): MemorySink
{
    $sink = new MemorySink;
    app()->instance(StreamContext::class, new StreamContext(new StreamSession('stream-llm', 'exec-llm', new StreamOptions, time()), $sink));

    return $sink;
}

beforeEach(function () {
    app()->forgetInstance(StreamContext::class);
    app()->forgetInstance(TraceContext::class);
    config()->set('agenticgraph.prism.cache_enabled', false);
});

afterEach(function () {
    app()->forgetInstance(StreamContext::class);
    app()->forgetInstance(TraceContext::class);
});

describe('builder', function () {
    it('is immutable: every method returns a new request and leaves the original as it was', function () {
        $node = new LlmProbeNode;
        [$base, $derived] = $node->call(function (LlmRequest $llm): array {
            $base = $llm->prompt('Say {x}')->vars(['x' => 'one']);

            return [$base, $base->vars(['x' => 'two'])->system('Be brief.')->maxTokens(9)];
        });

        expect($derived)->not->toBe($base)
            ->and($base->explain()['messages'])->toBe([['role' => 'user', 'content' => 'Say one', 'media' => []]])
            ->and($base->explain()['settings']['max_tokens']['source'])->toBe('config')
            ->and($derived->explain()['messages'][1]['content'])->toBe('Say two');
    });

    it('throws for two templates in one request', function (Closure $build) {
        expect(fn () => (new LlmProbeNode)->call($build))->toThrow(LogicException::class, 'one template');
    })->with([
        'prompt twice' => fn (LlmRequest $llm) => $llm->prompt('a')->prompt('b'),
        'prompt then chat' => fn (LlmRequest $llm) => $llm->prompt('a')->chat(ChatPromptTemplate::create()->user('b')),
        'chat then prompt' => fn (LlmRequest $llm) => $llm->chat(ChatPromptTemplate::create()->user('b'))->prompt('a'),
        'chat twice' => fn (LlmRequest $llm) => $llm->chat(ChatPromptTemplate::create())->chat(ChatPromptTemplate::create()),
    ]);

    it('throws for user(), assistant() or history() with prompt(), in either order', function (Closure $build) {
        expect(fn () => (new LlmProbeNode)->call($build))->toThrow(LogicException::class, 'Prism refuses a prompt and messages together');
    })->with([
        'prompt then user' => fn (LlmRequest $llm) => $llm->prompt('a')->user('b'),
        'prompt then assistant' => fn (LlmRequest $llm) => $llm->prompt('a')->assistant('b'),
        'prompt then history' => fn (LlmRequest $llm) => $llm->prompt('a')->history([new UserMessage('b')]),
        'user then prompt' => fn (LlmRequest $llm) => $llm->user('b')->prompt('a'),
    ]);

    it('throws without any input, for structured() without schema(), and for schema() with tools', function (Closure $build, string $message) {
        Prism::fake([llmText()]);

        expect(fn () => (new LlmProbeNode)->call($build))->toThrow(LogicException::class, $message);
    })->with([
        'no input' => [fn (LlmRequest $llm) => $llm->system('x')->withoutCache()->tools([])->run(), 'needs prompt(), chat(), user() or history()'],
        'no schema' => [fn (LlmRequest $llm) => $llm->prompt('a')->structured(), 'structured() needs schema()'],
        'schema with tools' => [fn (LlmRequest $llm) => $llm->prompt('a')->schema(llmSchema())->tools([llmTool()])->run(), 'schema() cannot be combined'],
    ]);
});

describe('templates and inline content', function () {
    it('wraps a prompt string in a template and interpolates it with vars()', function () {
        $requests = llmRecorded([fn (LlmRequest $llm) => $llm->prompt('Say {x} to {y}')->vars(['x' => 'hi'])->vars(['y' => 'Ann'])->text()], [llmText()]);

        expect($requests[0]->prompt())->toBe('Say hi to Ann');
    });

    it('throws for a missing template variable', function () {
        expect(fn () => (new LlmProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('Hello {name}')->text()))
            ->toThrow(TemplateException::class, 'Missing template variable: name');
    });

    it('never mutates a template: defaults apply and the template is formatted as it was', function () {
        $prompt = new PromptTemplate('Say {x} in {lang}', ['lang' => 'English']);
        $chat = ChatPromptTemplate::create()->user('Say {x}');

        $requests = llmRecorded([
            fn (LlmRequest $llm) => $llm->prompt($prompt)->vars(['x' => 'hi'])->text(),
            fn (LlmRequest $llm) => $llm->chat($chat)->vars(['x' => 'yo'])->user('extra')->text(),
        ], [llmText(), llmText()]);

        expect($requests[0]->prompt())->toBe('Say hi in English')
            ->and($prompt->format(['x' => 'a']))->toBe('Say a in English')
            ->and($chat->getMessages())->toHaveCount(1)
            ->and($requests[1]->messages())->toHaveCount(2);
    });

    it('never interpolates inline content: system(), user(), assistant() and history() reach the model unchanged', function () {
        $requests = llmRecorded([
            fn (LlmRequest $llm) => $llm->chat(ChatPromptTemplate::create()->user('Topic {t}'))
                ->vars(['t' => 'refunds', 'name' => 'SHOULD NOT APPEAR'])
                ->system(LLM_BRACES)
                ->user(LLM_BRACES)
                ->assistant(LLM_BRACES)
                ->history([['role' => 'user', 'content' => LLM_BRACES], new UserMessage(LLM_BRACES)])
                ->text(),
        ], [llmText()]);

        $messages = $requests[0]->messages();
        expect($messages)->toHaveCount(6)
            ->and($messages[0])->toBeInstanceOf(SystemMessage::class)
            ->and($messages[0]->content)->toBe(LLM_BRACES)
            ->and($messages[1]->content)->toBe('Topic refunds')
            ->and($messages[2])->toBeInstanceOf(UserMessage::class)
            ->and($messages[2]->content)->toBe(LLM_BRACES)
            ->and($messages[3])->toBeInstanceOf(AssistantMessage::class)
            ->and($messages[3]->content)->toBe(LLM_BRACES)
            ->and($messages[4]->content)->toBe(LLM_BRACES)
            ->and($messages[5]->content)->toBe(LLM_BRACES);
    });

    it('sends system() as the system prompt with prompt(), uninterpolated', function () {
        $requests = llmRecorded([fn (LlmRequest $llm) => $llm->system('You serve {brand}.')->prompt('Hi {n}')->vars(['n' => 'Ann', 'brand' => 'X'])->text()], [llmText()]);

        expect($requests[0]->prompt())->toBe('Hi Ann')
            ->and(array_map(fn ($m) => $m->content, $requests[0]->systemPrompts()))->toBe(['You serve {brand}.']);
    });

    it('appends history() in order, after the chat() messages, without a template too', function () {
        $requests = llmRecorded([
            fn (LlmRequest $llm) => $llm->history([new UserMessage('one'), ['role' => 'assistant', 'content' => 'two']])->user('three')->text(),
        ], [llmText()]);

        expect(array_map(fn ($m) => $m->content, $requests[0]->messages()))->toBe(['one', 'two', 'three'])
            ->and($requests[0]->prompt())->toBeNull();
    });

    it('attaches media to the last user turn', function () {
        $image = Image::fromUrl('https://example.test/a.png');
        $requests = llmRecorded([
            fn (LlmRequest $llm) => $llm->chat(ChatPromptTemplate::create()->user('first')->assistant('ok')->user('second'))->attach([$image])->text(),
            fn (LlmRequest $llm) => $llm->prompt('Describe')->attach([$image])->text(),
        ], [llmText(), llmText()]);

        expect(array_map(fn (array $m) => $m['media'] ?? null, MessageSerializer::toArray($requests[0]->messages())))->toBe([[], null, ['image']])
            ->and(array_column(MessageSerializer::toArray($requests[1]->messages()), 'media'))->toBe([['image']]);
    });
});

describe('settings', function () {
    it('resolves model and max tokens from config, then the node, then the call', function () {
        config()->set('agenticgraph.prism.model', 'openai:from-config');
        config()->set('agenticgraph.prism.max_tokens', 50);
        $text = fn (LlmRequest $llm) => $llm->prompt('a')->text();

        $requests = llmRecorded([$text], [llmText()]);
        $nodeRequests = llmRecorded([$text], [llmText()], new LlmProbeNode(model: 'anthropic:from-node', maxTokens: 20));
        $callRequests = llmRecorded([fn (LlmRequest $llm) => $llm->prompt('a')->model('openai:from-call')->maxTokens(7)->text()], [llmText()], new LlmProbeNode(model: 'anthropic:from-node', maxTokens: 20));

        expect([$requests[0]->model(), $requests[0]->maxTokens()])->toBe(['from-config', 50])
            ->and([$nodeRequests[0]->provider(), $nodeRequests[0]->model(), $nodeRequests[0]->maxTokens()])->toBe(['anthropic', 'from-node', 20])
            ->and([$callRequests[0]->model(), $callRequests[0]->maxTokens()])->toBe(['from-call', 7]);
    });

    it('merges providerOptions() over the node options, the call winning per key', function () {
        $requests = llmRecorded(
            [fn (LlmRequest $llm) => $llm->prompt('a')->providerOptions(['b' => 'call', 'c' => 3])->text()],
            [llmText()],
            new LlmProbeNode(providerOptions: ['a' => 1, 'b' => 'node']),
        );

        expect($requests[0]->providerOptions())->toBe(['a' => 1, 'b' => 'call', 'c' => 3]);
    });

    it('sends the configured timeouts on every path', function (Closure $build, array $fakes) {
        config()->set('agenticgraph.api.timeout', 41);
        config()->set('agenticgraph.api.connect_timeout', 7);

        $requests = llmRecorded([$build], $fakes);

        expect($requests)->not->toBeEmpty();
        foreach ($requests as $request) {
            expect($request->clientOptions())->toMatchArray(['timeout' => 41, 'connect_timeout' => 7]);
        }
    })->with([
        'text' => [fn (LlmRequest $llm) => $llm->prompt('a')->text(), fn () => [llmText()]],
        'structured' => [fn (LlmRequest $llm) => $llm->prompt('a')->schema(llmSchema())->structured(), fn () => [llmStructured()]],
        'tools' => [fn (LlmRequest $llm) => $llm->prompt('a')->tools([llmTool()])->run(), fn () => [llmText()]],
        'mcp' => [fn (LlmRequest $llm) => $llm->prompt('a')->mcp('docs', ['tools' => [llmTool()]])->run(), fn () => [llmText()]],
    ]);
});

describe('caching', function () {
    it('caches per request with withCache() and never changes the node', function () {
        $node = new LlmProbeNode;

        $requests = llmRecorded([
            fn (LlmRequest $llm) => $llm->prompt('a')->withCache(10)->text(),
            fn (LlmRequest $llm) => $llm->prompt('a')->withCache(10)->text(),
            fn (LlmRequest $llm) => $llm->prompt('b')->text(),
            fn (LlmRequest $llm) => $llm->prompt('b')->text(),
        ], array_fill(0, 4, llmText()), $node);

        expect($requests)->toHaveCount(3);
    });

    it('guard: withoutCache() wins over config on', function () {
        config()->set('agenticgraph.prism.cache_enabled', true);

        expect(llmRecorded(array_fill(0, 2, fn (LlmRequest $llm) => $llm->prompt('a')->withoutCache()->text()), [llmText(), llmText()]))->toHaveCount(2);
    });

    it('caches structured replies and flags the hit', function () {
        $node = new LlmProbeNode;
        $build = fn (LlmRequest $llm) => $llm->prompt('a')->schema(llmSchema())->withCache(10)->run();
        Prism::fake([llmStructured(['label' => 'yes'])]);

        $first = $node->call($build);
        $second = $node->call($build);

        expect($first->cached)->toBeFalse()
            ->and($second->cached)->toBeTrue()
            ->and($second->structured)->toBe(['label' => 'yes']);
    });

    it('never caches requests with tools or MCP', function (Closure $build) {
        $requests = llmRecorded([$build, $build], [llmText(), llmText()], new LlmProbeNode(cacheEnabled: true));

        expect($requests)->toHaveCount(2);
    })->with([
        'tools' => fn (LlmRequest $llm) => $llm->prompt('a')->tools([llmTool()])->withCache(10)->run(),
        'mcp' => fn (LlmRequest $llm) => $llm->prompt('a')->mcp('docs', ['tools' => [llmTool()]])->withCache(10)->run(),
    ]);

    it('keys the cache on the system prompt and the schema', function () {
        $requests = llmRecorded([
            fn (LlmRequest $llm) => $llm->system('A')->prompt('q')->withCache(10)->text(),
            fn (LlmRequest $llm) => $llm->system('A')->prompt('q')->withCache(10)->text(),
            fn (LlmRequest $llm) => $llm->system('B')->prompt('q')->withCache(10)->text(),
            fn (LlmRequest $llm) => $llm->prompt('q')->schema(llmSchema('label'))->withCache(10)->structured(),
            fn (LlmRequest $llm) => $llm->prompt('q')->schema(llmSchema('other'))->withCache(10)->structured(),
        ], [llmText(), llmText(), llmStructured(), llmStructured(['other' => 'x'])]);

        expect($requests)->toHaveCount(4);
    });

    it('uses MessageSerializer::hasMedia on every path: text parts cache, images do not', function () {
        $requests = llmRecorded([
            fn (LlmRequest $llm) => $llm->prompt('a')->attach([new Text('note')])->withCache(10)->text(),
            fn (LlmRequest $llm) => $llm->prompt('a')->attach([new Text('note')])->withCache(10)->text(),
            fn (LlmRequest $llm) => $llm->prompt('a')->attach([Image::fromUrl('https://example.test/a.png')])->withCache(10)->text(),
            fn (LlmRequest $llm) => $llm->prompt('a')->attach([Image::fromUrl('https://example.test/a.png')])->withCache(10)->text(),
        ], array_fill(0, 4, llmText()));

        expect($requests)->toHaveCount(3);
    });
});

describe('tools', function () {
    it('defaults to one step and Prism\'s tool choice; toolChoice() and maxSteps() override', function () {
        $requests = llmRecorded([
            fn (LlmRequest $llm) => $llm->prompt('a')->tools([llmTool()])->run(),
            fn (LlmRequest $llm) => $llm->prompt('a')->tools([llmTool()])->toolChoice('lookup')->maxSteps(5)->run(),
            fn (LlmRequest $llm) => $llm->prompt('a')->tools([llmTool()])->toolChoice(ToolChoice::Any)->run(),
        ], [llmText(), llmText(), llmText()]);

        expect([$requests[0]->maxSteps(), $requests[0]->toolChoice()])->toBe([1, null])
            ->and([$requests[1]->maxSteps(), $requests[1]->toolChoice()])->toBe([5, 'lookup'])
            ->and($requests[2]->toolChoice())->toBe(ToolChoice::Any);
    });

    it('adds MCP tools into the same path: wrapped, call type mcp, prompt and response in TokensUsed', function () {
        config()->set('agenticgraph.tracing.capture.prompts', true);
        config()->set('agenticgraph.tracing.capture.responses', true);
        Event::fake([TokensUsed::class]);
        $requests = llmRecorded([fn (LlmRequest $llm) => $llm->prompt('Use it')->mcp('docs', ['tools' => [llmTool('docs_search')]])->tools([llmTool()])->run()], [llmText('done')]);

        expect(array_map(fn (Tool $tool) => $tool->name(), $requests[0]->tools()))->toBe(['lookup', 'docs_search']);
        Event::assertDispatched(TokensUsed::class, fn (TokensUsed $e): bool => $e->callType === 'mcp' && $e->prompt === 'Use it' && $e->response === 'done');
    });

    it('resets tool wrapping per request: a later request without Prism tools still traces its calls', function () {
        Event::fake([ToolCallCaptured::class]);
        app()->instance(TraceContext::class, new TraceContext('exec-llm', 'span_root'));

        llmRecorded([
            fn (LlmRequest $llm) => $llm->prompt('first')->tools([llmTool()])->run(),
            fn (LlmRequest $llm) => $llm->prompt('second')->tools([])->run(),
        ], [llmText(''), llmText('')->withToolCalls([new ToolCall('c1', 'remote', ['q' => 'a'])])], new LlmProbeNode);

        Event::assertDispatched(ToolCallCaptured::class, fn (ToolCallCaptured $e): bool => $e->toolName === 'remote');
    });
});

describe('streaming matrix', function () {
    it('throws when a stream ends in Error, after the events were emitted', function () {
        $sink = llmBindSink();
        Prism::fake([llmText('partial')->withFinishReason(FinishReason::Error)]);

        expect(fn () => (new LlmProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('a')->text()))
            ->toThrow(PrismException::class, 'stream ended with an error');
        expect(iterator_count($sink->events()))->toBeGreaterThan(0);
    });

    it('returns Length with the real usage for a truncated stream (non-streamed truncation still throws on OpenAI)', function () {
        llmBindSink();
        Prism::fake([llmText('cut')->withFinishReason(FinishReason::Length)->withUsage(new Usage(70, 20))]);

        $response = (new LlmProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('a')->run());

        expect($response->finishReason)->toBe(FinishReason::Length)
            ->and($response->usage?->completionTokens)->toBe(20)
            ->and($response->toArray()['finish_reason'])->toBe('length');
    });

    it('streams text, chat, a Prism Schema with prompt() and single-step tools when a sink is bound', function (Closure $build, array $fakes) {
        $sink = llmBindSink();

        llmRecorded([$build], $fakes);

        expect(iterator_count($sink->events()))->toBeGreaterThan(0);
    })->with([
        'text' => [fn (LlmRequest $llm) => $llm->prompt('a')->text(), fn () => [llmText()]],
        'chat' => [fn (LlmRequest $llm) => $llm->chat(ChatPromptTemplate::create()->user('a'))->text(), fn () => [llmText()]],
        'schema' => [fn (LlmRequest $llm) => $llm->prompt('a')->schema(llmSchema())->structured(), fn () => [llmText('{"label":"ok"}')]],
        'single-step tools' => [fn (LlmRequest $llm) => $llm->prompt('a')->tools([llmTool()])->run(), fn () => [llmText()]],
    ]);

    it('streams multi-step tool runs and MCP', function (Closure $build) {
        $sink = llmBindSink();

        llmRecorded([$build], [llmText()]);

        expect(iterator_count($sink->events()))->toBeGreaterThan(0);
    })->with([
        'maxSteps > 1' => fn (LlmRequest $llm) => $llm->prompt('a')->tools([llmTool()])->maxSteps(3)->run(),
        'mcp' => fn (LlmRequest $llm) => $llm->prompt('a')->mcp('docs', ['tools' => [llmTool()]])->run(),
    ]);

    it('streams a tool run as one stream_start and stream_end around one step_start and step_finish per step; text is the final step', function () {
        $sink = llmBindSink();
        // The fake replays every step whatever maxSteps says, so the single-step path (streamed before multi-step streaming) shows it too.
        Prism::fake([llmTwoSteps()]);

        $response = (new LlmProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('a')->tools([llmTool()])->run());

        $events = array_values(array_filter(iterator_to_array($sink->events(), false), fn ($e) => ! str_starts_with($e->type(), 'text_')));
        $byType = fn (string $type): array => array_values(array_map(fn ($e) => $e->payload(), array_filter($events, fn ($e) => $e->type() === $type)));
        $usage = fn (int $in, int $out): array => ['prompt_tokens' => $in, 'completion_tokens' => $out, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => null, 'thought_tokens' => null];

        expect(array_map(fn ($e) => $e->type(), $events))->toBe(['stream_start', 'step_start', 'tool_call', 'tool_result', 'step_finish', 'step_start', 'step_finish', 'stream_end'])
            ->and(array_map(fn (array $p): array => [$p['finish_reason'], $p['usage']], $byType('step_finish')))->toBe([['ToolCalls', $usage(10, 2)], ['Stop', $usage(20, 5)]])
            ->and([$byType('stream_end')[0]['finish_reason'], $byType('stream_end')[0]['usage']])->toBe(['Stop', $usage(30, 7)])
            ->and($response->text)->toBe('Final.')
            ->and($response->usage?->promptTokens)->toBe(30)
            ->and(array_map(fn ($step) => [$step->text, count($step->toolCalls), count($step->toolResults), $step->usage?->promptTokens, $step->finishReason], $response->steps))
            ->toBe([['Checking.', 1, 1, 10, FinishReason::ToolCalls], ['Final.', 0, 0, 20, FinishReason::Stop]]);
    });

    it('returns the final step text of a streamed tool run, as non-streamed', function () {
        llmBindSink();
        Prism::fake([llmTwoSteps()]);

        expect((new LlmProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('a')->tools([llmTool()])->run())->text)->toBe('Final.');
    });

    it('keeps the steps of a non-streamed tool run with the final step as text', function () {
        Prism::fake([llmTwoSteps()]);

        $response = (new LlmProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('a')->tools([llmTool()])->maxSteps(2)->run());

        expect($response->text)->toBe('Final.')
            ->and(array_map(fn ($step) => $step->toArray()['text'], $response->steps))->toBe(['Checking.', 'Final.'])
            ->and($response->toArray()['steps'][0]['tool_results'][0]['tool_name'])->toBe('lookup')
            ->and($response->toArray()['steps'][1]['usage']['prompt_tokens'])->toBe(20);
    });
});

describe('terminals', function () {
    it('carries the cache buckets from Prism usage to TokensUsed and LlmResponse::toArray()', function () {
        Event::fake([TokensUsed::class]);
        Prism::fake([llmText('done')->withUsage(new Usage(20, 5, cacheWriteInputTokens: 7, cacheReadInputTokens: 90, thoughtTokens: 2))]);

        $response = (new LlmProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('a')->run());

        expect($response->toArray()['usage'])->toBe(['prompt_tokens' => 20, 'completion_tokens' => 5, 'thought_tokens' => 2, 'cache_read_tokens' => 90, 'cache_write_tokens' => 7]);
        Event::assertDispatched(TokensUsed::class, fn (TokensUsed $e) => $e->promptTokens === 20 && $e->cacheReadTokens === 90 && $e->cacheWriteTokens === 7 && $e->thoughtTokens === 2);
    });

    it('returns text, structured data and an LlmResponse', function () {
        Prism::fake([llmText('hello'), llmStructured(['label' => 'pos']), llmText('done')->withFinishReason(FinishReason::Stop)]);
        $node = new LlmProbeNode;

        expect($node->call(fn (LlmRequest $llm) => $llm->prompt('a')->text()))->toBe('hello')
            ->and($node->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema(llmSchema())->structured()))->toBe(['label' => 'pos']);

        $response = $node->call(fn (LlmRequest $llm) => $llm->prompt('a')->run());
        expect($response)->toBeInstanceOf(LlmResponse::class)
            ->and($response->text)->toBe('done')
            ->and($response->usage?->promptTokens)->toBe(5)
            ->and($response->finishReason)->toBe(FinishReason::Stop)
            ->and($response->cached)->toBeFalse();
    });

    it('stores an LlmResponse in state as plain data', function () {
        Prism::fake([llmText('done')->withToolCalls([new ToolCall('c1', 'lookup', ['q' => 'a'])])]);
        $response = (new LlmProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('a')->tools([llmTool()])->run());

        $stored = json_decode(json_encode((new State)->set('run', $response)->toArray()), true);

        expect($stored['run'])->toMatchArray(['text' => 'done', 'structured' => null, 'cached' => false])
            ->and($stored['run']['tool_calls'][0]['name'])->toBe('lookup')
            ->and($stored['run']['usage'])->toBe(['prompt_tokens' => 5, 'completion_tokens' => 1, 'thought_tokens' => null, 'cache_read_tokens' => null, 'cache_write_tokens' => null]);
    });

    it('dispatches the call types text, structured, tools, mcp and cached', function () {
        Event::fake([TokensUsed::class]);
        $node = new LlmProbeNode;
        Prism::fake([llmText(), llmStructured(), llmText(), llmText()]);

        $node->call(fn (LlmRequest $llm) => $llm->prompt('a')->withCache(10)->text());
        $node->call(fn (LlmRequest $llm) => $llm->prompt('a')->withCache(10)->text());
        $node->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema(llmSchema())->structured());
        $node->call(fn (LlmRequest $llm) => $llm->prompt('a')->tools([llmTool()])->run());
        $node->call(fn (LlmRequest $llm) => $llm->prompt('a')->mcp('docs', ['tools' => [llmTool()]])->run());

        $types = [];
        Event::assertDispatched(TokensUsed::class, function (TokensUsed $e) use (&$types): bool {
            $types[] = $e->callType;

            return true;
        });
        expect($types)->toBe(['text', 'cached', 'structured', 'tools', 'mcp']);
    });
});

describe('explain', function () {
    it('resolves the request without a model call', function () {
        config()->set('agenticgraph.prism.model', 'openai:gpt-test');
        config()->set('agenticgraph.prism.max_tokens', null);
        config()->set('agenticgraph.api.timeout', 30);
        $fake = Prism::fake([]);

        $explained = (new LlmProbeNode(maxTokens: 20, providerOptions: ['a' => 1]))->call(fn (LlmRequest $llm) => $llm
            ->system('Be brief.')
            ->prompt('Say {x}')->vars(['x' => 'hi'])
            ->providerOptions(['b' => 2])
            ->tools([llmTool()])->toolChoice('lookup')->maxSteps(4)
            ->explain());

        $fake->assertCallCount(0);
        expect($explained['messages'])->toBe([
            ['role' => 'system', 'content' => 'Be brief.'],
            ['role' => 'user', 'content' => 'Say hi', 'media' => []],
        ])
            ->and($explained['settings']['model'])->toBe(['value' => 'openai:gpt-test', 'source' => 'config'])
            ->and($explained['settings']['max_tokens'])->toBe(['value' => 20, 'source' => 'node'])
            ->and($explained['settings']['provider_options'])->toBe(['value' => ['a' => 1, 'b' => 2], 'source' => 'node, call'])
            ->and($explained['settings']['timeout'])->toBe(['value' => 30, 'source' => 'config'])
            ->and($explained['stream'])->toBeFalse()
            ->and($explained['cache'])->toMatchArray(['enabled' => false, 'reason' => 'off', 'source' => 'config'])
            ->and($explained['cache']['key'])->toStartWith('agenticgraph:prism:')
            ->and($explained['tools'])->toBe(['names' => ['lookup'], 'mcp' => [], 'choice' => 'lookup', 'max_steps' => 4]);
    });

    it('reports the stream decision, the cache decision and the key the call uses', function () {
        Prism::fake([llmText()]);
        $node = new LlmProbeNode;
        $request = $node->call(fn (LlmRequest $llm) => $llm->prompt('a')->withCache(10));
        $explained = $request->explain();

        expect($explained['cache'])->toMatchArray(['enabled' => true, 'reason' => null, 'source' => 'call', 'ttl_minutes' => 10]);
        $request->text();
        expect(cache()->has($explained['cache']['key']))->toBeTrue();

        llmBindSink();
        expect($request->explain()['stream'])->toBeTrue()
            ->and($request->explain()['cache']['reason'])->toBe('streamed')
            ->and($request->tools([llmTool()])->explain()['cache']['reason'])->toBe('tools');
    });
});
