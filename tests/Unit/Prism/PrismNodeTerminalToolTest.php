<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Step;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Llm\LlmExecutor;
use Shahzad\AgenticGraph\Llm\LlmRequest;
use Shahzad\AgenticGraph\Llm\LlmResponse;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Testing\Llm;
use Shahzad\AgenticGraph\Tools\TerminalResult;

/**
 * Terminal tools: the run ends when a terminal tool returns, with no further model call, and the reply is the tool's
 * text. The OpenAI provider runs against faked HTTP, so Prism's real tool loop runs.
 */
const TT_FIXED = 'Order A1 ships on 14 November 2026. Tracking: ZX-9911.';
const TT_PARAPHRASE = 'Good news! Your order should ship around mid-November.';
const TT_PREAMBLE = 'Let me check that for you. ';

class TerminalToolProbeNode extends PrismNode
{
    /** @var list<string> */
    public static array $ran = [];

    public function execute(StateContract $state): StateContract
    {
        return $state->set('run', $this->request($state)->run());
    }

    public function bare(): LlmRequest
    {
        return $this->llm()->prompt('Hi');
    }

    public function request(StateContract $state): LlmRequest
    {
        self::$ran = [];
        $tool = fn (string $name, Closure $handler): Tool => (new Tool)->as($name)->for($name)->withStringParameter('q', 'query')
            ->using(function (string $q) use ($name, $handler) {
                self::$ran[] = $name;

                return $handler($q);
            });
        $tools = [
            $tool('order_status', fn (string $q): string => TT_FIXED),
            $tool('second_terminal', fn (string $q): string => 'second'),
            $tool('lookup', fn (string $q): string => "found {$q}"),
            $tool('silent', fn (string $q): string => ''),
            $tool('explode', fn (string $q): string => throw new RuntimeException('boom')),
            $tool('remote', fn (string $q): string => 'ERROR: upstream down'),
            $tool('with_data', fn (string $q): TerminalResult => TerminalResult::reply(TT_FIXED, ['order' => $q, 'eta' => '2026-11-14'])),
        ];
        $request = $this->llm()->prompt('Where is order A1?')->tools($tools);
        if ($state->get('terminal') !== []) {
            $request = $request->terminalTools($state->get('terminal', ['order_status']), holdStepText: (bool) $state->get('hold', false));
        }
        if ($state->get('model') !== null) {
            $request = $request->model($state->get('model'));
        }

        return $state->get('steps') !== null ? $request->maxSteps($state->get('steps')) : $request;
    }
}

/**
 * @param  list<string>  $tools  the tools the model calls in this response
 */
function ttCalls(array $tools): array
{
    return ['id' => 'resp_1', 'model' => 'gpt-4o-mini', 'status' => 'completed', 'output' => array_map(fn (string $name, int $i): array => [
        'type' => 'function_call', 'id' => "fc_{$i}", 'call_id' => "cfc_{$i}", 'name' => $name, 'arguments' => json_encode(['q' => 'A1']), 'status' => 'completed',
    ], $tools, array_keys($tools)), 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];
}

function ttText(string $text): array
{
    return ['id' => 'resp_2', 'model' => 'gpt-4o-mini', 'status' => 'completed', 'output' => [[
        'type' => 'message', 'id' => 'msg_1', 'status' => 'completed', 'role' => 'assistant',
        'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]],
    ]], 'usage' => ['input_tokens' => 20, 'output_tokens' => 9]];
}

/**
 * One streamed OpenAI response: optional text, then the function calls.
 *
 * @param  list<string>  $tools
 */
function ttStream(array $tools, string $text = '', string $id = 'resp_s1'): string
{
    $events = [['type' => 'response.created', 'response' => ['id' => $id, 'model' => 'gpt-4o-mini']]];
    $output = [];
    $index = 0;
    if ($text !== '') {
        $message = ['type' => 'message', 'id' => "msg_{$id}", 'role' => 'assistant', 'status' => 'in_progress', 'content' => []];
        $events[] = ['type' => 'response.output_item.added', 'output_index' => $index, 'item' => $message];
        foreach (str_split($text, 9) as $chunk) {
            $events[] = ['type' => 'response.output_text.delta', 'item_id' => "msg_{$id}", 'output_index' => $index, 'content_index' => 0, 'delta' => $chunk];
        }
        $done = ['status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]] + $message;
        $events[] = ['type' => 'response.output_item.done', 'output_index' => $index++, 'item' => $done];
        $output[] = $done;
    }
    foreach ($tools as $i => $name) {
        $arguments = json_encode(['q' => 'A1']);
        $item = ['type' => 'function_call', 'id' => "fc_{$id}_{$i}", 'call_id' => "cfc_{$id}_{$i}", 'name' => $name, 'arguments' => ''];
        $events[] = ['type' => 'response.output_item.added', 'output_index' => $index, 'item' => $item];
        $events[] = ['type' => 'response.function_call_arguments.delta', 'item_id' => $item['id'], 'delta' => $arguments];
        $events[] = ['type' => 'response.function_call_arguments.done', 'item_id' => $item['id'], 'arguments' => $arguments];
        $done = ['arguments' => $arguments, 'status' => 'completed'] + $item;
        $events[] = ['type' => 'response.output_item.done', 'output_index' => $index++, 'item' => $done];
        $output[] = $done;
    }
    $events[] = ['type' => 'response.completed', 'response' => ['id' => $id, 'status' => 'completed', 'output' => $output, 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]];

    return implode('', array_map(fn (array $event): string => 'data: '.json_encode($event)."\n\n", $events))."data: [DONE]\n\n";
}

/** Fakes the streamed responses, in order. The last one repeats, so a second model call is seen, not an error. */
function ttFakeStreams(string ...$bodies): void
{
    $sequence = Http::sequence();
    foreach ($bodies as $body) {
        $sequence->push($body, 200, ['Content-Type' => 'text/event-stream']);
    }
    Http::fake(['*' => $sequence->whenEmpty(Http::response(ttStream([], 'UNEXPECTED CALL', 'resp_x'), 200, ['Content-Type' => 'text/event-stream']))]);
}

function ttSink(): MemorySink
{
    $sink = new MemorySink;
    app()->instance(StreamContext::class, new StreamContext(new StreamSession('stream-tt', 'exec-tt', new StreamOptions, time()), $sink));

    return $sink;
}

/** @return list<string> */
function ttTypes(MemorySink $sink): array
{
    return array_map(fn ($event) => $event->type(), iterator_to_array($sink->events(), false));
}

function ttDeltas(MemorySink $sink): string
{
    return implode('', array_map(fn ($event) => $event->type() === 'text_delta' ? $event->payload()['delta'] : '', iterator_to_array($sink->events(), false)));
}

function ttRun(array $state = []): LlmResponse
{
    return (new TerminalToolProbeNode)->execute(new State($state))->get('run');
}

beforeEach(function () {
    config()->set('prism.providers.openai.api_key', 'sk-test');
    app()->forgetInstance(StreamContext::class);
});

afterEach(function () {
    app()->forgetInstance(StreamContext::class);
});

it('ends a non-streamed run at the terminal tool: the reply is the tool text and no second model call is made', function () {
    ttFakeStreams(ttStream(['order_status']));

    $run = ttRun(['steps' => 3]);

    expect($run->text)->toBe(TT_FIXED)
        ->and($run->terminalTool)->toBe('order_status')
        ->and($run->terminalData)->toBe([])
        ->and($run->steps)->toHaveCount(1)
        ->and($run->steps[0]->toolCalls[0]->name)->toBe('order_status')
        ->and($run->steps[0]->toolResults[0]->result)->toBe(TT_FIXED)
        ->and($run->toolResults[0]->toolCallId)->toBe('fc_resp_s1_0')
        ->and($run->usage?->promptTokens)->toBe(10)
        ->and($run->usage?->completionTokens)->toBe(5);
    Http::assertSentCount(1);
});

it('ends a streamed run at the terminal tool: the reply is streamed as text_delta and nothing follows it', function () {
    ttFakeStreams(ttStream(['order_status']));
    $sink = ttSink();

    $run = ttRun(['steps' => 3]);

    expect($run->text)->toBe(TT_FIXED)
        ->and(ttDeltas($sink))->toBe(TT_FIXED)
        ->and(ttTypes($sink))->toBe(['stream_start', 'step_start', 'tool_call', 'tool_result', 'step_finish', 'text_start', 'text_delta', 'text_complete', 'stream_end']);
    $events = iterator_to_array($sink->events(), false);
    expect(array_map(fn ($event) => $event->seq(), $events))->toBe(range(1, 9))
        ->and(end($events)->payload()['usage']['prompt_tokens'])->toBe(10);
    Http::assertSentCount(1);
});

it('uses the terminal reply with the default single step', function () {
    ttFakeStreams(ttStream(['order_status']));

    expect(ttRun()->text)->toBe(TT_FIXED);
    Http::assertSentCount(1);
});

it('runs and records every tool of the step when a terminal and a normal tool are called together', function () {
    ttFakeStreams(ttStream(['lookup', 'order_status']));

    $run = ttRun(['steps' => 3]);

    expect(TerminalToolProbeNode::$ran)->toBe(['lookup', 'order_status'])
        ->and(array_map(fn ($result) => $result->result, $run->toolResults))->toBe(['found A1', TT_FIXED])
        ->and($run->text)->toBe(TT_FIXED)
        ->and($run->terminalTool)->toBe('order_status');
    Http::assertSentCount(1);
});

it('takes the reply of the first terminal tool in call order when a step calls two', function () {
    ttFakeStreams(ttStream(['second_terminal', 'order_status']));

    $run = ttRun(['steps' => 3, 'terminal' => ['order_status', 'second_terminal']]);

    expect($run->text)->toBe('second')
        ->and($run->terminalTool)->toBe('second_terminal')
        ->and($run->toolResults)->toHaveCount(2);
});

it('keeps model text before the terminal call in the step, not in the reply', function () {
    ttFakeStreams(ttStream(['order_status'], TT_PREAMBLE));
    $sink = ttSink();

    $run = ttRun(['steps' => 3]);

    expect($run->text)->toBe(TT_FIXED)
        ->and($run->steps[0]->text)->toBe(TT_PREAMBLE)
        // Default: the model text was streamed before the tool ran, then the reply follows as its own text block.
        ->and(ttDeltas($sink))->toBe(TT_PREAMBLE.TT_FIXED)
        ->and(array_count_values(ttTypes($sink))['text_start'])->toBe(2);
});

it('with holdStepText does not stream the text of a step that calls a terminal tool', function () {
    ttFakeStreams(ttStream(['order_status'], TT_PREAMBLE));
    $sink = ttSink();

    $run = ttRun(['steps' => 3, 'hold' => true]);

    expect(ttDeltas($sink))->toBe(TT_FIXED)
        ->and($run->text)->toBe(TT_FIXED)
        ->and($run->steps[0]->text)->toBe(TT_PREAMBLE)
        ->and(ttTypes($sink))->toBe(['stream_start', 'step_start', 'tool_call', 'tool_result', 'step_finish', 'text_start', 'text_delta', 'text_complete', 'stream_end']);
    Http::assertSentCount(1);
});

it('with holdStepText streams the held text when the step calls no terminal tool', function () {
    ttFakeStreams(ttStream(['lookup'], TT_PREAMBLE), ttStream([], 'It was found.', 'resp_s2'));
    $sink = ttSink();

    $run = ttRun(['steps' => 3, 'hold' => true]);

    expect(ttDeltas($sink))->toBe(TT_PREAMBLE.'It was found.')
        ->and($run->text)->toBe('It was found.')
        ->and($run->terminalTool)->toBeNull()
        ->and($run->steps[0]->text)->toBe(TT_PREAMBLE);
    // The held text of a step is sent when the step ends, before its step_finish.
    $types = ttTypes($sink);
    expect(array_search('text_delta', $types, true))->toBeLessThan(array_search('step_finish', $types, true))
        ->and(array_search('tool_result', $types, true))->toBeLessThan(array_search('text_delta', $types, true));
    Http::assertSentCount(2);
});

it('ends with an empty reply and no text events when the terminal tool returns no text', function () {
    ttFakeStreams(ttStream(['silent']));
    $sink = ttSink();

    $run = ttRun(['steps' => 3, 'terminal' => ['silent']]);

    expect($run->text)->toBe('')
        ->and($run->terminalTool)->toBe('silent')
        ->and(ttTypes($sink))->toBe(['stream_start', 'step_start', 'tool_call', 'tool_result', 'step_finish', 'stream_end']);
    Http::assertSentCount(1);
});

it('ends as before when maxSteps is reached before a terminal tool runs', function () {
    ttFakeStreams(ttStream(['lookup']));

    $run = ttRun(['steps' => 1]);

    expect($run->text)->toBe('')
        ->and($run->terminalTool)->toBeNull()
        ->and($run->toolResults[0]->result)->toBe('found A1');
    Http::assertSentCount(1);
});

it('continues the run when the terminal tool fails', function (string $tool) {
    ttFakeStreams(ttStream([$tool]), ttStream([], 'Sorry, I could not look that up.', 'resp_s2'));

    $run = ttRun(['steps' => 3, 'terminal' => [$tool]]);

    expect($run->text)->toBe('Sorry, I could not look that up.')
        ->and($run->terminalTool)->toBeNull()
        ->and($run->toolResults[0]->error)->not->toBeNull();
    Http::assertSentCount(2);
})->with(['explode', 'remote']);

it('carries the data of a TerminalResult and serializes the terminal fields', function () {
    ttFakeStreams(ttStream(['with_data']));

    $run = ttRun(['steps' => 3, 'terminal' => ['with_data']]);

    expect($run->text)->toBe(TT_FIXED)
        ->and($run->terminalTool)->toBe('with_data')
        ->and($run->terminalData)->toBe(['order' => 'A1', 'eta' => '2026-11-14'])
        ->and($run->toArray()['terminal_tool'])->toBe('with_data')
        ->and($run->toArray()['terminal_data'])->toBe(['order' => 'A1', 'eta' => '2026-11-14'])
        ->and($run->withWarnings(['w'])->terminalTool)->toBe('with_data')
        ->and($run->withSources([])->terminalData)->toBe(['order' => 'A1', 'eta' => '2026-11-14']);
});

it('treats a TerminalResult from a tool that is not terminal as a normal result, with a warning', function () {
    Log::spy();
    ttFakeStreams(ttStream(['with_data']), ttStream([], TT_PARAPHRASE, 'resp_s2'));

    $run = ttRun(['steps' => 3]);

    expect($run->text)->toBe(TT_PARAPHRASE)->and($run->terminalTool)->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'with_data') && str_contains($message, 'terminalTools'))->once();
    Http::assertSentCount(2);
});

it('leaves a run without terminal tools on the non-streamed path', function () {
    Http::fake(['*' => Http::sequence()->push(ttCalls(['order_status']))->push(ttText(TT_PARAPHRASE))]);

    $run = ttRun(['steps' => 3, 'terminal' => []]);

    expect($run->text)->toBe(TT_PARAPHRASE)->and($run->terminalTool)->toBeNull();
    Http::assertSentCount(2);
});

it('refuses a provider that is not verified for terminal tools, before any request', function () {
    Http::fake();

    expect(fn () => ttRun(['model' => 'perplexity:sonar']))
        ->toThrow(LogicException::class, 'terminalTools() is not supported for the [perplexity] provider');
    Http::assertNothingSent();
});

it('rejects terminal tool names that are not tools of the request', function () {
    $node = new TerminalToolProbeNode;

    expect(fn () => $node->request(new State(['terminal' => ['order_status', 'nope']]))->explain())
        ->toThrow(LogicException::class, 'terminalTools(): [nope] is not a tool of this request')
        ->and(fn () => $node->bare()->terminalTools(['x'])->explain())
        ->toThrow(LogicException::class, 'terminalTools() needs tools() or mcp()');
});

it('explains the terminal tools and the hold option', function () {
    $explained = (new TerminalToolProbeNode)->request(new State(['hold' => true]))->explain();

    expect($explained['tools']['terminal'])->toBe(['order_status'])
        ->and($explained['tools']['hold_step_text'])->toBeTrue();
});

it('records the end reason and the tool on the token event', function () {
    Event::fake([TokensUsed::class]);
    ttFakeStreams(ttStream(['order_status']));

    ttRun(['steps' => 3]);

    Event::assertDispatched(TokensUsed::class, fn (TokensUsed $event): bool => $event->endReason === 'terminal_tool'
        && $event->terminalTool === 'order_status'
        && $event->streamed === false);
});

it('leaves the end reason empty when no terminal tool ran', function () {
    Event::fake([TokensUsed::class]);
    ttFakeStreams(ttStream(['lookup']));

    ttRun(['steps' => 1]);

    Event::assertDispatched(TokensUsed::class, fn (TokensUsed $event): bool => $event->endReason === null && $event->terminalTool === null);
});

it('ends at a terminal tool result of an Llm::fake() fixture', function () {
    $step = fn (string $text, array $calls, array $results): Step => new Step($text, $calls === [] ? FinishReason::Stop : FinishReason::ToolCalls, $calls, $results, [], new Usage(7, 3), new Meta('fake', 'fake'), [], []);
    Llm::fake([TextResponseFake::make()->withText(TT_PARAPHRASE)->withSteps(collect([
        $step('', [new ToolCall('call_1', 'order_status', ['q' => 'A1'])], [new ToolResult('call_1', 'order_status', ['q' => 'A1'], TT_FIXED)]),
        $step(TT_PARAPHRASE, [], []),
    ]))]);

    $run = ttRun(['steps' => 3]);

    expect($run->text)->toBe(TT_FIXED)
        ->and($run->terminalTool)->toBe('order_status')
        ->and($run->steps)->toHaveCount(1);
});

/**
 * One streamed tool call of order_status, in the wire format of each provider that terminalTools() allows.
 */
function ttProviderStream(string $provider): string
{
    $sse = fn (array $events): string => implode('', array_map(fn (array $event): string => 'data: '.json_encode($event)."\n\n", $events));
    $arguments = json_encode(['q' => 'A1']);

    return match ($provider) {
        'openai' => ttStream(['order_status']),
        'anthropic' => implode('', array_map(fn (array $event): string => "event: {$event['type']}\ndata: ".json_encode($event)."\n\n", [
            ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-test', 'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 1]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'order_status', 'input' => new stdClass]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $arguments]],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use', 'stop_sequence' => null], 'usage' => ['output_tokens' => 5]],
            ['type' => 'message_stop'],
        ])),
        'gemini' => $sse([
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'order_status', 'args' => ['q' => 'A1']]]]], 'finishReason' => 'STOP', 'index' => 0]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15], 'modelVersion' => 'gemini-test', 'responseId' => 'r1'],
        ]),
        'ollama' => json_encode(['model' => 'llama-test', 'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'order_status', 'arguments' => ['q' => 'A1']]]]], 'done' => false])."\n"
            .json_encode(['model' => 'llama-test', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 10, 'eval_count' => 5])."\n",
        // Mistral sends a tool call whole, in one chunk.
        'mistral' => $sse([
            ['id' => 'cmpl-1', 'model' => 'test-model', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => null]]],
            ['id' => 'cmpl-1', 'model' => 'test-model', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'order_status', 'arguments' => $arguments], 'index' => 0]]], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]],
        ])."data: [DONE]\n\n",
        // The OpenAI chat completions stream format.
        default => $sse([
            ['id' => 'chatcmpl-1', 'model' => 'test-model', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'order_status', 'arguments' => '']]]], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-1', 'model' => 'test-model', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => $arguments]]]], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-1', 'model' => 'test-model', 'choices' => [['index' => 0, 'delta' => new stdClass, 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15], 'x_groq' => ['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]]],
        ])."data: [DONE]\n\n",
    };
}

it('ends a run at the terminal tool on every provider terminalTools() allows', function (string $provider) {
    config()->set("prism.providers.{$provider}.api_key", 'key-test');
    // A second model call would get this 500 and fail the run.
    Http::fake(['*' => Http::sequence()->push(ttProviderStream($provider), 200, ['Content-Type' => 'text/event-stream'])->whenEmpty(Http::response('second model call', 500))]);
    $sink = ttSink();

    $run = ttRun(['steps' => 3, 'model' => "{$provider}:test-model"]);

    expect($run->text)->toBe(TT_FIXED)
        ->and($run->terminalTool)->toBe('order_status')
        ->and($run->toolCalls)->toHaveCount(1)
        ->and($run->toolResults[0]->result)->toBe(TT_FIXED)
        ->and($run->usage?->promptTokens)->toBe(10)
        ->and(ttDeltas($sink))->toBe(TT_FIXED)
        ->and(array_slice(ttTypes($sink), -5))->toBe(['step_finish', 'text_start', 'text_delta', 'text_complete', 'stream_end']);
    Http::assertSentCount(1);
})->with(['openai', 'anthropic', 'deepseek', 'gemini', 'groq', 'mistral', 'ollama', 'openrouter', 'xai', 'z']);

it('allows terminal tools for exactly the providers that have a fixture test', function () {
    $allowed = (new ReflectionClassConstant(LlmExecutor::class, 'TERMINAL_TOOL_PROVIDERS'))->getValue();

    expect($allowed)->toBe(['openai', 'anthropic', 'deepseek', 'gemini', 'groq', 'mistral', 'ollama', 'openrouter', 'xai', 'z']);
});
