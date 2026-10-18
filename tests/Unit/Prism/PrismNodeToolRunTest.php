<?php

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolOutput;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Llm\LlmResponse;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;

/**
 * Real Prism tool execution: the OpenAI provider runs against faked HTTP responses, so the handlers really run.
 */
class ToolRunProbeNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $tools = [
            (new Tool)->as('lookup')->for('Look up an order')->withStringParameter('q', 'query')
                ->using(fn (string $q): string => "found {$q}"),
            (new Tool)->as('explode')->for('Always fails')->withStringParameter('q', 'query')
                ->using(fn (string $q): string => throw new RuntimeException("boom {$q}")),
            (new Tool)->as('remote')->for('Reports an error string')->withStringParameter('q', 'query')
                ->using(fn (string $q): string => 'ERROR: upstream down'),
            (new Tool)->as('structured')->for('Returns a ToolOutput')->withStringParameter('q', 'query')
                ->using(fn (string $q): ToolOutput => new ToolOutput(result: "out {$q}")),
        ];

        return match ($state->get('helper')) {
            'tools' => $state->set('run', $this->llm()->prompt('Use the tools')->tools($tools)->run()),
            'multi-step' => $state->set('run', $this->llm()->prompt('Use the tools')->tools($tools)->toolChoice(ToolChoice::Any)->maxSteps(3)->run()),
        };
    }
}

/**
 * @param  list<array{0: string, 1: string, 2: array<string, mixed>}>  $calls  [call id, tool name, arguments]
 */
function openAiToolCalls(array $calls, string $id = 'resp_1'): array
{
    return [
        'id' => $id,
        'model' => 'gpt-4o-mini',
        'status' => 'completed',
        'output' => array_map(fn (array $call): array => [
            'type' => 'function_call',
            'id' => $call[0],
            'call_id' => 'c'.$call[0],
            'name' => $call[1],
            'arguments' => json_encode($call[2]),
            'status' => 'completed',
        ], $calls),
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

function openAiText(string $text): array
{
    return [
        'id' => 'resp_text',
        'model' => 'gpt-4o-mini',
        'status' => 'completed',
        'output' => [[
            'type' => 'message',
            'id' => 'msg_1',
            'status' => 'completed',
            'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]],
        ]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

/**
 * One streamed OpenAI response with the given function calls.
 *
 * @param  list<array{0: string, 1: string, 2: array<string, mixed>}>  $calls
 */
function openAiToolCallStream(array $calls): string
{
    $events = [['type' => 'response.created', 'response' => ['id' => 'resp_s', 'model' => 'gpt-4o-mini']]];
    foreach ($calls as $index => [$id, $name, $arguments]) {
        $item = ['type' => 'function_call', 'id' => $id, 'call_id' => 'c'.$id, 'name' => $name, 'arguments' => ''];
        $events[] = ['type' => 'response.output_item.added', 'output_index' => $index, 'item' => $item];
        $events[] = ['type' => 'response.function_call_arguments.delta', 'item_id' => $id, 'delta' => json_encode($arguments)];
        $events[] = ['type' => 'response.function_call_arguments.done', 'item_id' => $id, 'arguments' => json_encode($arguments)];
        $events[] = ['type' => 'response.output_item.done', 'output_index' => $index, 'item' => ['arguments' => json_encode($arguments), 'status' => 'completed'] + $item];
    }
    $events[] = ['type' => 'response.completed', 'response' => ['id' => 'resp_s', 'status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]];

    return implode('', array_map(fn (array $event): string => 'data: '.json_encode($event)."\n\n", $events))."data: [DONE]\n\n";
}

beforeEach(function () {
    config()->set('prism.providers.openai.api_key', 'sk-test');
    app()->forgetInstance(StreamContext::class);
});

afterEach(function () {
    app()->forgetInstance(StreamContext::class);
});

$fourCalls = [
    ['fc_1', 'lookup', ['q' => 'A1']],
    ['fc_2', 'explode', ['q' => 'A2']],
    ['fc_3', 'remote', ['q' => 'A3']],
    ['fc_4', 'structured', ['q' => 'A4']],
];

function expectFourResults(LlmResponse $run): void
{
    expect($run->toolCalls)->toHaveCount(4)
        ->and(array_map(fn ($call) => $call->id, $run->toolCalls))->toBe(['fc_1', 'fc_2', 'fc_3', 'fc_4'])
        ->and(array_map(fn ($result) => $result->toArray(), $run->toolResults))->toBe([
            ['tool_call_id' => 'fc_1', 'tool_name' => 'lookup', 'arguments' => ['q' => 'A1'], 'result' => 'found A1', 'error' => null],
            ['tool_call_id' => 'fc_2', 'tool_name' => 'explode', 'arguments' => ['q' => 'A2'], 'result' => 'Tool execution error: boom A2. This error occurred during tool execution, not due to invalid parameters.', 'error' => 'Tool execution error: boom A2. This error occurred during tool execution, not due to invalid parameters.'],
            ['tool_call_id' => 'fc_3', 'tool_name' => 'remote', 'arguments' => ['q' => 'A3'], 'result' => 'ERROR: upstream down', 'error' => 'ERROR: upstream down'],
            ['tool_call_id' => 'fc_4', 'tool_name' => 'structured', 'arguments' => ['q' => 'A4'], 'result' => 'out A4', 'error' => null],
        ]);
}

it('returns calls and results from a non-streamed tool call', function () use ($fourCalls) {
    Http::fake(['api.openai.com/*' => Http::response(openAiToolCalls($fourCalls))]);

    $run = (new ToolRunProbeNode)->execute(new State(['helper' => 'tools']))->get('run');

    expectFourResults($run);
    expect($run->text)->toBe('');
    Http::assertSentCount(1); // single step: the results are not sent back
});

it('returns calls and results from a streamed tool call, and still streams the tool results', function () use ($fourCalls) {
    Http::fake(['api.openai.com/*' => Http::response(openAiToolCallStream($fourCalls), 200, ['Content-Type' => 'text/event-stream'])]);
    $sink = new MemorySink;
    app()->instance(StreamContext::class, new StreamContext(new StreamSession('stream-tr', 'exec-tr', new StreamOptions, time()), $sink));

    $run = (new ToolRunProbeNode)->execute(new State(['helper' => 'tools']))->get('run');

    expectFourResults($run);
    $types = array_map(fn ($event) => $event->type(), iterator_to_array($sink->events()));
    expect(array_count_values($types)['tool_result'] ?? 0)->toBe(4);
});

it('exposes the tool calls and results of every step of a multi-step run', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(openAiToolCalls([['fc_1', 'lookup', ['q' => 'S1']]], 'resp_1'))
        ->push(openAiToolCalls([['fc_2', 'explode', ['q' => 'S2']]], 'resp_2'))
        ->push(openAiText('All done.'))]);

    $response = (new ToolRunProbeNode)->execute(new State(['helper' => 'multi-step']))->get('run');

    expect($response)->toBeInstanceOf(LlmResponse::class)
        ->and($response->text)->toBe('All done.')
        ->and(array_map(fn ($call) => $call->id, $response->toolCalls))->toBe(['fc_1', 'fc_2'])
        ->and(array_map(fn ($result) => [$result->toolCallId, $result->result, $result->error !== null], $response->toolResults))->toBe([
            ['fc_1', 'found S1', false],
            ['fc_2', 'Tool execution error: boom S2. This error occurred during tool execution, not due to invalid parameters.', true],
        ]);
});

it('stores an LlmResponse in state as plain data', function () use ($fourCalls) {
    Http::fake(['api.openai.com/*' => Http::response(openAiToolCalls($fourCalls))]);

    $state = (new ToolRunProbeNode)->execute(new State(['helper' => 'tools']));
    $stored = json_decode(json_encode($state->toArray()), true);

    expect($stored['run']['tool_calls'][0]['name'])->toBe('lookup')
        ->and($stored['run']['tool_results'][1]['error'])->toStartWith('Tool execution error: boom A2')
        ->and($stored['run']['text'])->toBe('');
});
