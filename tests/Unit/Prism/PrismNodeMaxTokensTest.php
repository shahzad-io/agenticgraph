<?php

use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Exceptions\MissingModelException;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\PromptTemplate;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Testing\Llm;

/**
 * Runs one request path, chosen by state 'path'. $maxTokens, $providerOptions and $model come from the constructor so one class covers precedence.
 */
class MaxTokensProbeNode extends PrismNode
{
    public function __construct(?int $maxTokens = null, array $providerOptions = [], ?string $model = null)
    {
        $this->maxTokens = $maxTokens;
        $this->providerOptions = $providerOptions;
        $this->model = $model;
    }

    public function execute(StateContract $state): StateContract
    {
        $llm = $this->llm()->withoutCache();
        $tool = (new Tool)->as('lookup')->for('Look up a value')
            ->withStringParameter('q', 'query')
            ->using(fn (string $q): string => 'value');
        $schema = new ObjectSchema('answer', 'answer', [new StringSchema('text', 'text')], ['text']);

        match ($state->get('path')) {
            'prompt' => $llm->prompt('Say {x}')->vars(['x' => 'hi'])->text(),
            'prompt template' => $llm->prompt(PromptTemplate::create('Say {x}'))->vars(['x' => 'hi'])->text(),
            'chat' => $llm->chat(ChatPromptTemplate::create()->user('Say {x}'))->vars(['x' => 'hi'])->text(),
            'history' => $llm->history([new UserMessage('Say hi')])->text(),
            'structured' => $llm->prompt('Say hi')->schema($schema)->structured(),
            'tools' => $llm->prompt('Use the tool')->tools([$tool])->run(),
            'multi-step tools' => $llm->prompt('Use the tool')->tools([$tool])->toolChoice(ToolChoice::Any)->maxSteps(5)->run(),
            'mcp' => $llm->prompt('Use the tool')->mcp('unused-server', ['tools' => [$tool]])->run(),
        };

        return $state;
    }
}

/**
 * Runs one path and returns the maxTokens of every recorded request.
 *
 * @return list<?int>
 */
function maxTokensRecorded(string $path, bool $streamed, ?int $nodeLimit, array $fakes): array
{
    return array_map(fn ($request) => $request->maxTokens(), requestsRecorded(new MaxTokensProbeNode($nodeLimit), $path, $streamed, $fakes));
}

/**
 * Runs one path on the node and returns every recorded Prism request.
 *
 * @return list<mixed>
 */
function requestsRecorded(MaxTokensProbeNode $node, string $path, bool $streamed, array $fakes): array
{
    $fake = Prism::fake($fakes);

    app()->forgetInstance(StreamContext::class);
    if ($streamed) {
        app()->instance(StreamContext::class, new StreamContext(
            new StreamSession('stream-mt', 'exec-mt', new StreamOptions, time()),
            new MemorySink
        ));
    }

    try {
        $node->execute(new State(['path' => $path]));
    } finally {
        app()->forgetInstance(StreamContext::class);
    }

    $requests = [];
    $fake->assertRequest(function (array $recorded) use (&$requests): void {
        $requests = $recorded;
    });

    return $requests;
}

function maxTokensText(): TextResponseFake
{
    return TextResponseFake::make()->withText('ok')->withUsage(new Usage(5, 1));
}

it('uses the node property, then config, then sends no limit', function (?int $node, mixed $config, ?int $expected) {
    $prism = config('agenticgraph.prism');
    unset($prism['max_tokens']);
    config()->set('agenticgraph.prism', $prism);
    if ($config !== 'missing') {
        config()->set('agenticgraph.prism.max_tokens', $config);
    }

    expect(maxTokensRecorded('prompt', false, $node, [maxTokensText()]))->toBe([$expected]);
})->with([
    'property wins over config' => [20, 50, 20],
    'config when no property' => [null, 50, 50],
    'config as env string' => [null, '75', 75],
    'none when config null' => [null, null, null],
    'none when key missing' => [null, 'missing', null],
]);

dataset('every path', [
    'prompt' => ['prompt', false, fn () => [maxTokensText()]],
    'prompt streamed' => ['prompt', true, fn () => [maxTokensText()]],
    'prompt template' => ['prompt template', false, fn () => [maxTokensText()]],
    'prompt template streamed' => ['prompt template', true, fn () => [maxTokensText()]],
    'chat' => ['chat', false, fn () => [maxTokensText()]],
    'chat streamed' => ['chat', true, fn () => [maxTokensText()]],
    'history' => ['history', false, fn () => [maxTokensText()]],
    'history streamed' => ['history', true, fn () => [maxTokensText()]],
    'structured' => ['structured', false, fn () => [StructuredResponseFake::make()->withStructured(['text' => 'ok'])->withUsage(new Usage(5, 1))]],
    'structured streamed' => ['structured', true, fn () => [TextResponseFake::make()->withText('{"text":"ok"}')->withUsage(new Usage(5, 1))]],
    'structured streamed fallback' => ['structured', true, fn () => [
        TextResponseFake::make()->withText('not json')->withUsage(new Usage(5, 1)),
        StructuredResponseFake::make()->withStructured(['text' => 'ok'])->withUsage(new Usage(5, 1)),
    ]],
    'tools' => ['tools', false, fn () => [maxTokensText()]],
    'tools streamed' => ['tools', true, fn () => [maxTokensText()]],
    'multi-step tools' => ['multi-step tools', false, fn () => [maxTokensText()]],
    'mcp' => ['mcp', false, fn () => [maxTokensText()]],
]);

it('sends the node limit from every path', function (string $path, bool $streamed, array $fakes) {
    $limits = maxTokensRecorded($path, $streamed, 20, $fakes);

    expect($limits)->not->toBeEmpty()
        ->and(array_unique($limits))->toBe([20]);
})->with('every path');

it('sends the node provider options from every path', function (string $path, bool $streamed, array $fakes) {
    $options = ['use_tool_calling' => true];
    $requests = requestsRecorded(new MaxTokensProbeNode(providerOptions: $options), $path, $streamed, $fakes);

    expect($requests)->not->toBeEmpty();
    foreach ($requests as $request) {
        expect($request->providerOptions())->toBe($options);
    }
})->with('every path');

it('sends no provider options when the node sets none', function () {
    $requests = requestsRecorded(new MaxTokensProbeNode, 'prompt', false, [maxTokensText()]);

    expect($requests[0]->providerOptions())->toBe([]);
});

it('throws a clear error when no model is configured', function () {
    config()->set('agenticgraph.prism.model', null);
    Prism::fake([maxTokensText()]);

    expect(fn () => (new MaxTokensProbeNode)->execute(new State(['path' => 'prompt'])))
        ->toThrow(MissingModelException::class, 'Set AGENTICGRAPH_PRISM_MODEL');
    expect(MissingModelException::notConfigured()->getMessage())->toContain('$model property');
});

it('uses the node model when config has none', function () {
    config()->set('agenticgraph.prism.model', null);
    $llm = Llm::fake([maxTokensText()]);
    (new MaxTokensProbeNode(model: 'anthropic:claude-x'))->execute(new State(['path' => 'prompt']));

    $llm->assertSettings(['model' => ['value' => 'anthropic:claude-x', 'source' => 'node']]);
});

it('throws when the model has no provider prefix', function (string $model) {
    expect(fn () => (new MaxTokensProbeNode(model: $model))->execute(new State(['path' => 'prompt'])))
        ->toThrow(MissingModelException::class, 'has no provider');
})->with(['gpt-4o', ':gpt-4o', 'openai:']);

it('covers both requests of the streamed structured fallback', function () {
    expect(maxTokensRecorded('structured', true, 20, [
        TextResponseFake::make()->withText('not json')->withUsage(new Usage(5, 1)),
        StructuredResponseFake::make()->withStructured(['text' => 'ok'])->withUsage(new Usage(5, 1)),
    ]))->toBe([20, 20]);
});
