<?php

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Execution\LoopContext;
use Shahzad\AgenticGraph\Llm\LlmRequest;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\Prompts;
use Shahzad\AgenticGraph\Routers\PrismRouter;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Testing\Llm;
use Shahzad\AgenticGraph\Tracing\TraceContext;

class LlmFakeProbeNode extends PrismNode
{
    protected ?string $model = 'openai:node-model';

    protected ?int $maxTokens = 300;

    public function execute(StateContract $state): StateContract
    {
        return $state;
    }

    public function call(Closure $build): mixed
    {
        return $build($this->llm());
    }
}

function llmFakePromptDir(): string
{
    return sys_get_temp_dir().'/ag2-llm-fake-'.getmypid();
}

beforeEach(function () {
    app()->forgetInstance(StreamContext::class);
    app()->forgetInstance(TraceContext::class);
    config()->set('agenticgraph.prism.model', 'openai:config-model');
    config()->set('agenticgraph.prism.cache_enabled', false);
    config()->set('agenticgraph.router.retry_delay_ms', 0);
    config()->set('agenticgraph.prompts.path', llmFakePromptDir());
    @mkdir(llmFakePromptDir().'/route', 0777, true);
    file_put_contents(llmFakePromptDir().'/route/pick.md', "---\nname: route.pick\nversion: 3\n---\n\n## system\n\nYou route support tickets.\n\n## user\n\nRoutes:\n{routes}\n\nTicket: {ticket}\n");
});

afterEach(function () {
    @unlink(llmFakePromptDir().'/route/pick.md');
    @rmdir(llmFakePromptDir().'/route');
    @rmdir(llmFakePromptDir());
});

it('records what the builder resolved: node, vars, history, context items, prompt file and settings with sources', function () {
    file_put_contents(llmFakePromptDir().'/route/pick.md', "---\nname: route.pick\nversion: 3\n---\n\n## user\n\n{ticket}\n{context}\n");
    $llm = Llm::fake(['Sure.']);

    $reply = (new LlmFakeProbeNode)->call(fn (LlmRequest $request) => $request->chat(Prompts::get('route.pick'))
        ->vars(['ticket' => 'Refund?'])
        ->context([['id' => 'doc-1', 'content' => 'Refunds take 5 days.']])
        ->user('My order {id} is late.')
        ->maxTokens(50)
        ->text());

    $call = $llm->recorded()[0];
    expect($reply)->toBe('Sure.')
        ->and($call['node'])->toBe('Llm Fake Probe')
        ->and($call['vars'])->toBe(['ticket' => 'Refund?', 'context' => 'Refunds take 5 days.'])
        ->and($call['template_vars'])->toBe(['ticket', 'context'])
        ->and($call['history'])->toBe([['role' => 'user', 'content' => 'My order {id} is late.']])
        ->and($call['context']['items'][0]['id'])->toBe('doc-1')
        ->and($call['prompt'])->toMatchArray(['name' => 'route.pick', 'version' => '3'])
        ->and($call['settings']['model'])->toBe(['value' => 'openai:node-model', 'source' => 'node'])
        ->and($call['settings']['max_tokens'])->toBe(['value' => 50, 'source' => 'call'])
        ->and($call['messages'][1]['content'])->toBe('My order {id} is late.');

    $llm->assertPrompted(fn (array $call) => $call['vars']['ticket'] === 'Refund?' && $call['prompt']['name'] === 'route.pick');
    $llm->assertSettings(['model' => 'openai:node-model', 'max_tokens' => ['value' => 50, 'source' => 'call']]);
    $llm->assertNotInterpolated('id');
    $llm->assertSentCount(1);
    expect(fn () => $llm->assertPrompted(fn (array $call) => $call['vars']['ticket'] === 'Other'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $llm->assertSettings(['max_tokens' => ['value' => 50, 'source' => 'node']]))->toThrow(AssertionFailedError::class)
        ->and(fn () => $llm->assertNotInterpolated('ticket'))->toThrow(AssertionFailedError::class, 'Variable [ticket] was interpolated')
        ->and(fn () => $llm->assertNothingSent())->toThrow(AssertionFailedError::class);
});

it('does not count a messages placeholder as interpolated', function () {
    $llm = Llm::fake(['ok']);
    (new LlmFakeProbeNode)->call(fn (LlmRequest $request) => $request->chat(ChatPromptTemplate::create()->messagesPlaceholder('history')->user('{q}'))
        ->vars(['history' => [['role' => 'user', 'content' => 'hi {q}']], 'q' => 'next'])->text());

    $llm->assertNotInterpolated('history');
    expect($llm->recorded()[0]['template_vars'])->toBe(['q']);
});

it('asserts nothing was sent before any call', function () {
    Llm::fake()->assertNothingSent();
});

it('answers in order: text, structured data, a tool run, and a failure', function () {
    $llm = Llm::fake([
        'plain',
        ['decision' => 'full'],
        TextResponseFake::make()->withToolCalls([new ToolCall('c1', 'lookup', ['id' => 1])])->withFinishReason(FinishReason::ToolCalls),
        new RuntimeException('provider down'),
    ]);
    $node = new LlmFakeProbeNode;

    expect($node->call(fn (LlmRequest $r) => $r->prompt('a')->text()))->toBe('plain')
        ->and($node->call(fn (LlmRequest $r) => $r->prompt('b')->schema(new ObjectSchema('d', 'd', [new StringSchema('decision', 'd')], ['decision']))->structured()))->toBe(['decision' => 'full'])
        ->and($node->call(fn (LlmRequest $r) => $r->prompt('c')->tools([])->run()->toolCalls[0]->name))->toBe('lookup')
        ->and(fn () => $node->call(fn (LlmRequest $r) => $r->prompt('d')->text()))->toThrow(RuntimeException::class, 'provider down')
        ->and(fn () => $node->call(fn (LlmRequest $r) => $r->prompt('e')->text()))->toThrow(LogicException::class, 'no queued response left');
    $llm->assertSentCount(5);
});

function llmFakeToolCall(string ...$names): TextResponseFake
{
    $calls = array_map(fn (int $i, string $name) => new ToolCall("c{$i}", $name, ['q' => $name]), array_keys($names), $names);

    return TextResponseFake::make()->withToolCalls($calls)->withFinishReason(FinishReason::ToolCalls);
}

it('runs the handler of a queued tool call, sends its result back and takes the next response', function () {
    $ran = [];
    $tool = (new Tool)->as('ping')->for('p')->withStringParameter('q', 'q')->using(function (string $q) use (&$ran): string {
        $ran[] = $q;

        return 'pong';
    });
    Llm::fake([llmFakeToolCall('ping'), 'final text']);

    $response = (new LlmFakeProbeNode)->call(fn (LlmRequest $r) => $r->system('s')->user('u')->tools([$tool])->maxSteps(3)->run());

    expect($ran)->toBe(['ping'])
        ->and($response->text)->toBe('final text')
        ->and(array_map(fn ($step) => [count($step->toolCalls), array_map(fn ($r) => $r->result, $step->toolResults)], $response->steps))
        ->toBe([[1, ['pong']], [0, []]]);
});

function llmFakeEcho(string $name, array &$ran): Tool
{
    return (new Tool)->as($name)->for($name)->withStringParameter('q', 'q')->using(function (string $q) use (&$ran, $name): string {
        $ran[] = $name;

        return "{$name} done";
    });
}

it('answers a call with no tools as before: one queued response, nothing run', function () {
    $llm = Llm::fake(['plain', llmFakeToolCall('ping')]);
    $node = new LlmFakeProbeNode;

    expect($node->call(fn (LlmRequest $r) => $r->prompt('a')->text()))->toBe('plain')
        // A tool call queued for a request without tools passes through: there is no handler to run.
        ->and($node->call(fn (LlmRequest $r) => $r->prompt('b')->run()->toolCalls))->toHaveCount(0);
    $llm->assertSentCount(2);
});

it('runs every tool call of one step, in order, and sends all results back in one message', function () {
    $ran = [];
    $llm = Llm::fake([llmFakeToolCall('alpha', 'beta', 'alpha'), 'done']);

    $tools = [llmFakeEcho('alpha', $ran), llmFakeEcho('beta', $ran)];

    $response = (new LlmFakeProbeNode)->call(fn (LlmRequest $r) => $r->prompt('a')->tools($tools)->maxSteps(2)->run());

    $second = null;
    $llm->prism()->assertRequest(function (array $requests) use (&$second): void {
        $second = end($requests)->messages();
    });
    expect($ran)->toBe(['alpha', 'beta', 'alpha'])
        ->and($response->text)->toBe('done')
        ->and(array_map(fn ($r) => $r->result, $response->steps[0]->toolResults))->toBe(['alpha done', 'beta done', 'alpha done'])
        ->and(array_map(fn ($m) => $m::class, array_slice($second, -2)))->toBe([AssistantMessage::class, ToolResultMessage::class])
        ->and(end($second)->toolResults)->toHaveCount(3);
});

it('sends the error of a handler that throws back as the tool result and goes on, as a provider loop does', function () {
    $tool = (new Tool)->as('boom')->for('b')->withStringParameter('q', 'q')->using(fn (string $q): string => throw new RuntimeException('db down'));
    Llm::fake([llmFakeToolCall('boom'), 'sorry']);

    $response = (new LlmFakeProbeNode)->call(fn (LlmRequest $r) => $r->prompt('a')->tools([$tool])->maxSteps(2)->run());

    expect($response->text)->toBe('sorry')
        ->and($response->toolResults[0]->error)->toContain('db down')
        ->and((string) $response->toolResults[0]->result)->toContain('db down');
});

it('runs the handler at the default one step but takes no next response', function () {
    $ran = [];
    $llm = Llm::fake([llmFakeToolCall('ping'), 'unused']);

    $tool = llmFakeEcho('ping', $ran);

    $response = (new LlmFakeProbeNode)->call(fn (LlmRequest $r) => $r->prompt('a')->tools([$tool])->run());

    expect($ran)->toBe(['ping'])->and($response->toolResults[0]->result)->toBe('ping done');
    $llm->prism()->assertCallCount(1);
});

it('runs the handler in a streamed run and streams tool_call, tool_result and the final text', function () {
    $sink = new MemorySink;
    app()->instance(StreamContext::class, new StreamContext(new StreamSession('s', 'e', new StreamOptions, time()), $sink));
    $ran = [];
    Llm::fake([llmFakeToolCall('ping'), 'final text']);

    $tool = llmFakeEcho('ping', $ran);

    $response = (new LlmFakeProbeNode)->call(fn (LlmRequest $r) => $r->prompt('a')->tools([$tool])->maxSteps(3)->run());

    $types = array_values(array_filter(array_map(fn ($e) => $e->type(), iterator_to_array($sink->events(), false)), fn (string $t) => ! in_array($t, ['text_start', 'text_complete'], true)));
    expect($ran)->toBe(['ping'])
        ->and($response->text)->toBe('final text')
        ->and(array_values(array_unique($types)))->toBe(['stream_start', 'step_start', 'tool_call', 'tool_result', 'step_finish', 'text_delta', 'stream_end']);
});

it('ends at a terminal tool whose handler ran, without taking the next response', function () {
    $tool = (new Tool)->as('status')->for('s')->withStringParameter('q', 'q')->using(fn (string $q): string => "Order {$q} ships today.");
    $llm = Llm::fake([llmFakeToolCall('status'), 'never']);

    $response = (new LlmFakeProbeNode)->call(fn (LlmRequest $r) => $r->prompt('a')->tools([$tool])->terminalTools(['status'])->maxSteps(3)->run());

    expect($response->text)->toBe('Order status ships today.')->and($response->terminalTool)->toBe('status');
    $llm->prism()->assertCallCount(1);
});

it('keeps the old behavior with runTools: false: the tool call is the final result and no handler runs', function () {
    $ran = [];
    Llm::fake([llmFakeToolCall('ping')], runTools: false);

    $tool = llmFakeEcho('ping', $ran);

    $response = (new LlmFakeProbeNode)->call(fn (LlmRequest $r) => $r->prompt('a')->tools([$tool])->maxSteps(3)->run());

    expect($ran)->toBe([])->and($response->toolCalls[0]->name)->toBe('ping')->and($response->toolResults)->toBe([]);
});

it('retries a structured reply that is not JSON with the next queued response, in one recorded call', function () {
    $llm = Llm::fake(['not json', '{"decision": "partial"}']);
    $schema = new ObjectSchema('d', 'd', [new StringSchema('decision', 'd')], ['decision']);

    $response = (new LlmFakeProbeNode)->call(fn (LlmRequest $r) => $r->prompt('decide')->schema($schema)->run());

    expect($response->structured)->toBe(['decision' => 'partial'])->and($response->attempts)->toBe(2);
    $llm->assertSentCount(1);
    $llm->prism()->assertCallCount(2);
});

it('records PrismRouter calls at the executor and lets a queued failure drive the router retry', function () {
    $llm = Llm::fake([new RuntimeException('timeout'), ['route' => 'billing']]);

    $route = (new PrismRouter(['billing' => 'bill', 'general' => 'gen'], defaultRoute: 'general'))->route(new State(['message' => 'invoice']));

    expect($route)->toBe('billing');
    $llm->assertSentCount(2);
    $llm->assertSettings(['model' => ['value' => 'openai:config-model', 'source' => 'config'], 'max_tokens' => ['value' => 1024, 'source' => 'node']]);
    $llm->assertPrompted(fn (array $call) => $call['node'] === 'PrismRouter' && $call['prompt'] === null && str_contains($call['messages'][0]['content'], '- billing: bill'));
});

it('sends a router prompt file with routingVars(), carries its name, version and hash, and records its vars', function () {
    Event::fake([TokensUsed::class]);
    $llm = Llm::fake([['route' => 'billing']]);
    $router = new class(['billing' => 'bill', 'general' => 'gen'], defaultRoute: 'general', prompt: Prompts::get('route.pick')) extends PrismRouter
    {
        protected function routingVars(StateContract $state): array
        {
            return [...parent::routingVars($state), 'ticket' => (string) $state->get('message')];
        }
    };

    expect($router->route(new State(['message' => 'invoice {x}'])))->toBe('billing');

    $call = $llm->recorded()[0];
    expect(array_map(fn (array $m) => [$m['role'], $m['content']], $call['messages']))->toBe([
        ['system', 'You route support tickets.'],
        ['user', "Routes:\n- billing: bill\n- general: gen\n\nTicket: invoice {x}"],
    ])
        ->and($call['vars']['ticket'])->toBe('invoice {x}')
        ->and($call['template_vars'])->toBe(['routes', 'ticket'])
        ->and(Event::dispatched(TokensUsed::class)[0][0]->promptFile)->toBe(Prompts::get('route.pick')->meta());
});

it('appends the loop context to the last message of a router prompt, as it does to an inline prompt', function () {
    $llm = Llm::fake([['route' => 'billing'], ['route' => 'billing']]);
    $template = ChatPromptTemplate::create()->user("Routes:\n{routes}");
    $make = fn (?ChatPromptTemplate $prompt) => new class(['billing' => 'bill'], prompt: $prompt) extends PrismRouter
    {
        protected function shouldAppendLoopContext(?LoopContext $loop): bool
        {
            return true;
        }

        protected function formatLoopContextForPrompt(LoopContext $loop): string
        {
            return "\nLoop context: 2";
        }

        protected function buildPrompt(StateContract $state): string
        {
            return "Routes:\n".$this->routesText();
        }
    };
    $loop = (new ReflectionClass(LoopContext::class))->newInstanceWithoutConstructor();

    (new ReflectionMethod(PrismRouter::class, 'routeWithLoop'))->invoke($make($template), new State, $loop);
    (new ReflectionMethod(PrismRouter::class, 'routeWithLoop'))->invoke($make(null), new State, $loop);

    [$withTemplate, $inline] = $llm->recorded();
    expect($withTemplate['messages'][0]['content'])->toBe("Routes:\n- billing: bill\nLoop context: 2")
        ->and($inline['messages'][0]['content'])->toBe($withTemplate['messages'][0]['content']);
});
