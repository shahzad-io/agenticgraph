<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Llm\LlmRequest;
use Shahzad\AgenticGraph\Llm\LlmResponse;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;

/**
 * A tool run replayed as history on a later turn: toReplay() keeps what each provider needs to accept the run again,
 * replay() and a messages placeholder send it back. Providers run against faked HTTP, so Prism's real maps run.
 */
class ReplayProbeNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }

    public function req(string $model): LlmRequest
    {
        return $this->llm()->model($model)->withoutCache();
    }
}

function replayWeather(): Tool
{
    return (new Tool)->as('weather')->for('Current weather for a city')->withStringParameter('city', 'City name')
        ->using(fn (string $city): string => "Sunny, 21C in {$city}");
}

/** A terminal tool the model never calls: it forces Prism's streamed tool loop. */
function replayNeverCalled(): Tool
{
    return (new Tool)->as('never_called')->for('Do not call this tool')->withStringParameter('x', 'unused')->using(fn (string $x): string => $x);
}

const REPLAY_USAGE = ['input_tokens' => 10, 'output_tokens' => 5];

function replayOpenAiCall(): array
{
    return ['id' => 'resp_1', 'model' => 'gpt-5-mini', 'status' => 'completed', 'usage' => REPLAY_USAGE, 'output' => [
        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'gAAAA-opaque'],
        ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'weather', 'arguments' => '{"city":"Paris"}', 'status' => 'completed'],
    ]];
}

function replayOpenAiText(string $text = 'Sunny, 21C.'): array
{
    return ['id' => 'resp_2', 'model' => 'gpt-5-mini', 'status' => 'completed', 'usage' => REPLAY_USAGE, 'output' => [
        ['type' => 'message', 'id' => 'msg_1', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]],
    ]];
}

/** The same two responses as server-sent events. */
function replayOpenAiStream(array $response): string
{
    $events = [['type' => 'response.created', 'response' => ['id' => $response['id'], 'model' => 'gpt-5-mini']]];
    foreach ($response['output'] as $index => $item) {
        $events[] = ['type' => 'response.output_item.added', 'output_index' => $index, 'item' => $item];
        if ($item['type'] === 'function_call') {
            $events[] = ['type' => 'response.function_call_arguments.done', 'item_id' => $item['id'], 'arguments' => $item['arguments']];
        }
        if ($item['type'] === 'message') {
            $events[] = ['type' => 'response.output_text.delta', 'item_id' => $item['id'], 'output_index' => $index, 'content_index' => 0, 'delta' => $item['content'][0]['text']];
        }
        $events[] = ['type' => 'response.output_item.done', 'output_index' => $index, 'item' => $item];
    }
    $events[] = ['type' => 'response.completed', 'response' => $response];

    return implode('', array_map(fn (array $event): string => 'data: '.json_encode($event)."\n\n", $events))."data: [DONE]\n\n";
}

/**
 * Fakes one provider endpoint: the turn-1 bodies in order, then any later request is checked by $rule (the provider's
 * replay rule). A rule that returns an error body answers 400 with it, as the provider does.
 *
 * @param  list<array|string>  $turn1
 */
function replayFake(string $url, array $turn1, array $final, ?Closure $rule = null): ArrayObject
{
    $log = new ArrayObject(['n' => 0, 'requests' => []]);
    Http::fake([$url => function (Request $request) use ($turn1, $final, $rule, $log) {
        $n = $log['n']++;
        if ($n < count($turn1)) {
            return is_string($turn1[$n]) ? Http::response($turn1[$n], 200, ['Content-Type' => 'text/event-stream']) : Http::response($turn1[$n]);
        }
        $log['requests'] = [...$log['requests'], $request->data()];
        $error = $rule !== null ? $rule($request->data()) : null;

        return $error !== null ? Http::response($error, 400) : Http::response($final);
    }]);

    return $log;
}

/** OpenAI: a function_call needs the reasoning item it was emitted with (the error body seen live on gpt-5-mini). */
function replayOpenAiRule(array $data): ?array
{
    $seen = [];
    foreach ($data['input'] as $item) {
        if (($item['type'] ?? null) === 'reasoning') {
            $seen[$item['id']] = true;
        }
        if (($item['type'] ?? null) === 'function_call' && $item['id'] === 'fc_1' && ! isset($seen['rs_1'])) {
            return ['error' => ['message' => "Item 'fc_1' of type 'function_call' was provided without its required 'reasoning' item: 'rs_1'.", 'type' => 'invalid_request_error', 'param' => 'input', 'code' => null]];
        }
    }

    return null;
}

/** A replay that went through JSON, as an app stores it. */
function replayStored(LlmResponse $response): array
{
    return json_decode(json_encode($response->toReplay()), true);
}

beforeEach(function () {
    config()->set('agenticgraph.prism.cache_enabled', false);
    foreach (['openai', 'anthropic', 'gemini', 'openrouter'] as $provider) {
        config()->set("prism.providers.{$provider}.api_key", 'test-key');
    }
    Http::preventStrayRequests();
});

describe('OpenAI reasoning tool run', function () {
    it('replays a non-streamed run with its reasoning item and call ids', function () {
        $log = replayFake('api.openai.com/*', [replayOpenAiCall(), replayOpenAiText()], replayOpenAiText('Rainy.'), replayOpenAiRule(...));
        $node = new ReplayProbeNode('n');

        $r1 = $node->req('openai:gpt-5-mini')->prompt('Weather in Paris?')->tools([replayWeather()])->maxSteps(3)->run();
        $r2 = $node->req('openai:gpt-5-mini')->history([new UserMessage('Weather in Paris?')])->replay(replayStored($r1))
            ->prompt('And in Rome?')->tools([replayWeather()])->maxSteps(3)->run();

        $input = $log['requests'][0]['input'];
        $types = array_column($input, 'type');
        expect($r2->text)->toBe('Rainy.')
            ->and(array_search('reasoning', $types, true))->toBeLessThan(array_search('function_call', $types, true))
            ->and(collect($input)->firstWhere('type', 'reasoning')['encrypted_content'])->toBe('gAAAA-opaque')
            ->and(collect($input)->firstWhere('type', 'function_call_output')['call_id'])->toBe('call_1');
    });

    it('replays a streamed run with its reasoning item and call ids', function () {
        $log = replayFake('api.openai.com/*', [replayOpenAiStream(replayOpenAiCall()), replayOpenAiStream(replayOpenAiText())], replayOpenAiText('Rainy.'), replayOpenAiRule(...));
        $node = new ReplayProbeNode('n');

        $r1 = $node->req('openai:gpt-5-mini')->prompt('Weather in Paris?')->tools([replayWeather(), replayNeverCalled()])->terminalTools(['never_called'])->maxSteps(3)->run();
        $node->req('openai:gpt-5-mini')->replay(replayStored($r1))->prompt('And in Rome?')->tools([replayWeather()])->maxSteps(3)->run();

        $input = $log['requests'][0]['input'];
        expect(array_column($r1->steps[0]->providerContent['output_items'] ?? [], 'type'))->toBe(['reasoning', 'function_call'])
            ->and(collect($input)->firstWhere('type', 'reasoning')['id'])->toBe('rs_1')
            ->and(collect($input)->firstWhere('type', 'function_call_output')['call_id'])->toBe('call_1');
    });

    it('keeps the reasoning items for another model of the same provider', function () {
        // Live on 2026-11-16: gpt-5-mini tool calls replayed to gpt-5-nano without their reasoning items fail with the same 400.
        $log = replayFake('api.openai.com/*', [replayOpenAiCall(), replayOpenAiText()], replayOpenAiText('Rainy.'), replayOpenAiRule(...));
        $node = new ReplayProbeNode('n');

        $r1 = $node->req('openai:gpt-5-mini')->prompt('Weather in Paris?')->tools([replayWeather()])->maxSteps(3)->run();
        $node->req('openai:gpt-5-nano')->replay(replayStored($r1))->prompt('And in Rome?')->run();

        expect(array_column($log['requests'][0]['input'], 'type'))->toContain('reasoning');
    });
});

describe('the replay payload', function () {
    it('is plain data that survives JSON, with the provider, the model, the steps and the tool call ids', function () {
        replayFake('api.openai.com/*', [replayOpenAiCall(), replayOpenAiText()], replayOpenAiText());
        $r1 = (new ReplayProbeNode('n'))->req('openai:gpt-5-mini')->prompt('Weather in Paris?')->tools([replayWeather()])->maxSteps(3)->run();
        $replay = $r1->toReplay();

        expect(json_decode(json_encode($replay), true))->toBe($replay)
            ->and($replay['version'])->toBe(1)
            ->and($replay['provider'])->toBe('openai')
            ->and($replay['model'])->toBe('gpt-5-mini')
            ->and(array_column($replay['messages'], 'role'))->toBe(['assistant', 'tool', 'assistant'])
            ->and($replay['messages'][1]['tool_results'][0]['call_id'])->toBe('call_1')
            ->and($r1->toolResults[0]->callId)->toBe('call_1')
            ->and($r1->toArray()['provider'])->toBe('openai')
            ->and($r1->toArray()['model'])->toBe('gpt-5-mini')
            ->and($r1->toArray()['steps'][0]['provider_content']['output_items'][0]['id'])->toBe('rs_1');
    });

    it('replays a text reply as one assistant message', function () {
        replayFake('api.openai.com/*', [replayOpenAiText('Hello.')], replayOpenAiText());
        $r1 = (new ReplayProbeNode('n'))->req('openai:gpt-5-mini')->prompt('Hi')->run();

        expect($r1->toReplay()['messages'])->toBe([['role' => 'assistant', 'content' => 'Hello.', 'tool_calls' => [], 'provider_content' => []]]);
    });

    it('refuses a replay from another provider', function () {
        replayFake('api.openai.com/*', [replayOpenAiCall(), replayOpenAiText()], replayOpenAiText());
        $node = new ReplayProbeNode('n');
        $r1 = $node->req('openai:gpt-5-mini')->prompt('Weather in Paris?')->tools([replayWeather()])->maxSteps(3)->run();

        expect(fn () => $node->req('anthropic:claude-sonnet-4-5')->replay(replayStored($r1))->prompt('And in Rome?')->run())
            ->toThrow(InvalidArgumentException::class, 'A replay from [openai:gpt-5-mini] cannot be sent to the [anthropic] provider');
    });

    it('refuses an unknown version', function () {
        expect(fn () => (new ReplayProbeNode('n'))->req('openai:gpt-5-mini')->replay(['version' => 2, 'provider' => 'openai', 'model' => 'm', 'messages' => []])->prompt('x')->run())
            ->toThrow(InvalidArgumentException::class, 'version');
    });

    it('goes through a messages placeholder, between the turns of a stored chat', function () {
        $log = replayFake('api.openai.com/*', [replayOpenAiCall(), replayOpenAiText()], replayOpenAiText('Rainy.'), replayOpenAiRule(...));
        $node = new ReplayProbeNode('n');
        $r1 = $node->req('openai:gpt-5-mini')->prompt('Weather in Paris?')->tools([replayWeather()])->maxSteps(3)->run();

        $chat = ChatPromptTemplate::create()->messagesPlaceholder('history')->user('And in {city}?');
        $node->req('openai:gpt-5-mini')->chat($chat)->vars(['city' => 'Rome', 'history' => [['role' => 'user', 'content' => 'Weather in Paris?'], replayStored($r1)]])->run();

        $input = $log['requests'][0]['input'];
        expect(array_column($input, 'type'))->toContain('reasoning')
            ->and($input[0]['role'])->toBe('user')
            ->and(end($input)['content'][0]['text'])->toBe('And in Rome?');
    });
});

describe('other providers', function () {
    it('replays the Anthropic signed thinking block unchanged before the tool use', function () {
        $call = ['id' => 'msg_a1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-5', 'stop_reason' => 'tool_use', 'usage' => REPLAY_USAGE, 'content' => [
            ['type' => 'thinking', 'thinking' => 'Need the weather tool.', 'signature' => 'sig_1'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'weather', 'input' => ['city' => 'Paris']],
        ]];
        $text = ['id' => 'msg_a2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-5', 'stop_reason' => 'end_turn', 'usage' => REPLAY_USAGE, 'content' => [['type' => 'text', 'text' => 'Sunny, 21C.']]];
        $log = replayFake('api.anthropic.com/*', [$call, $text], $text);
        $node = new ReplayProbeNode('n');

        $r1 = $node->req('anthropic:claude-sonnet-4-5')->prompt('Weather in Paris?')->tools([replayWeather()])->maxSteps(3)->run();
        $node->req('anthropic:claude-sonnet-4-5')->replay(replayStored($r1))->prompt('And in Rome?')->run();

        $assistant = collect($log['requests'][0]['messages'])->first(fn (array $m): bool => $m['role'] === 'assistant');
        expect($assistant['content'][0])->toBe(['type' => 'thinking', 'thinking' => 'Need the weather tool.', 'signature' => 'sig_1'])
            ->and($assistant['content'][1]['type'])->toBe('tool_use');
    });

    it('replays the Gemini thought signature of a function call', function () {
        $part = fn (array $parts, string $finish = 'STOP'): array => ['candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => $finish]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5], 'modelVersion' => 'gemini-2.5-flash'];
        $log = replayFake('generativelanguage.googleapis.com/*', [
            $part([['functionCall' => ['name' => 'weather', 'args' => ['city' => 'Paris']], 'thoughtSignature' => 'sig_g']]),
            $part([['text' => 'Sunny, 21C.']]),
        ], $part([['text' => 'Rainy.']]));
        $node = new ReplayProbeNode('n');

        $r1 = $node->req('gemini:gemini-2.5-flash')->prompt('Weather in Paris?')->tools([replayWeather()])->maxSteps(3)->run();
        $node->req('gemini:gemini-2.5-flash')->replay(replayStored($r1))->prompt('And in Rome?')->run();

        $parts = collect($log['requests'][0]['contents'])->firstWhere('role', 'model')['parts'];
        expect($parts[0]['thoughtSignature'] ?? null)->toBe('sig_g');
    });

    it('replays the OpenRouter reasoning details', function () {
        $choice = fn (array $message, string $finish): array => ['id' => 'gen-1', 'model' => 'vendor/model', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', ...$message], 'finish_reason' => $finish]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]];
        $details = [['type' => 'reasoning.encrypted', 'data' => 'opaque', 'id' => 'r1', 'format' => 'openai-responses-v1']];
        $log = replayFake('openrouter.ai/*', [
            $choice(['content' => '', 'reasoning' => 'Need the tool.', 'reasoning_details' => $details, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '{"city":"Paris"}']]]], 'tool_calls'),
            $choice(['content' => 'Sunny, 21C.'], 'stop'),
        ], $choice(['content' => 'Rainy.'], 'stop'));
        $node = new ReplayProbeNode('n');

        $r1 = $node->req('openrouter:vendor/model')->prompt('Weather in Paris?')->tools([replayWeather()])->maxSteps(3)->run();
        $node->req('openrouter:vendor/model')->replay(replayStored($r1))->prompt('And in Rome?')->run();

        $assistant = collect($log['requests'][0]['messages'])->first(fn (array $m): bool => $m['role'] === 'assistant' && isset($m['tool_calls']));
        expect($assistant['reasoning_details'] ?? null)->toBe($details);
    });
});
