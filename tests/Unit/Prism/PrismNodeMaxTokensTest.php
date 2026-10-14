<?php

use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\PromptTemplate;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;

/**
 * Calls one helper, chosen by state 'helper'. $maxTokens comes from state so one class covers precedence.
 */
class MaxTokensProbeNode extends PrismNode
{
    public function __construct(?int $maxTokens = null)
    {
        $this->maxTokens = $maxTokens;
    }

    public function execute(StateContract $state): StateContract
    {
        $this->withoutCache();
        $tool = (new Tool)->as('lookup')->for('Look up a value')
            ->withStringParameter('q', 'query')
            ->using(fn (string $q): string => 'value');
        $schema = new ObjectSchema('answer', 'answer', [new StringSchema('text', 'text')], ['text']);

        match ($state->get('helper')) {
            'prompt' => $this->prompt('Say {x}', ['x' => 'hi']),
            'promptTemplate' => $this->promptTemplate(PromptTemplate::create('Say {x}'), ['x' => 'hi']),
            'chatPrompt' => $this->chatPrompt(ChatPromptTemplate::create()->user('Say {x}'), ['x' => 'hi']),
            'promptMessages' => $this->promptMessages([new UserMessage('Say hi')]),
            'structured' => $this->structured('Say hi', $schema),
            'tools' => $this->tools('Use the tool', [$tool]),
            'agent' => $this->agent('Use the tool', [$tool]),
            'withMcp' => $this->withMcp('unused-server', ['tools' => [$tool]])->withPrompt('Use the tool')->asText(),
        };

        return $state;
    }
}

/**
 * Runs one helper and returns the maxTokens of every recorded request.
 *
 * @return list<?int>
 */
function maxTokensRecorded(string $helper, bool $streamed, ?int $nodeLimit, array $fakes): array
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
        (new MaxTokensProbeNode($nodeLimit))->execute(new State(['helper' => $helper]));
    } finally {
        app()->forgetInstance(StreamContext::class);
    }

    $limits = [];
    $fake->assertRequest(function (array $recorded) use (&$limits): void {
        $limits = array_map(fn ($request) => $request->maxTokens(), $recorded);
    });

    return $limits;
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

it('sends the node limit from every helper', function (string $helper, bool $streamed, array $fakes) {
    $limits = maxTokensRecorded($helper, $streamed, 20, $fakes);

    expect($limits)->not->toBeEmpty()
        ->and(array_unique($limits))->toBe([20]);
})->with([
    'prompt' => ['prompt', false, fn () => [maxTokensText()]],
    'prompt streamed' => ['prompt', true, fn () => [maxTokensText()]],
    'promptTemplate' => ['promptTemplate', false, fn () => [maxTokensText()]],
    'promptTemplate streamed' => ['promptTemplate', true, fn () => [maxTokensText()]],
    'chatPrompt' => ['chatPrompt', false, fn () => [maxTokensText()]],
    'chatPrompt streamed' => ['chatPrompt', true, fn () => [maxTokensText()]],
    'promptMessages' => ['promptMessages', false, fn () => [maxTokensText()]],
    'promptMessages streamed' => ['promptMessages', true, fn () => [maxTokensText()]],
    'structured' => ['structured', false, fn () => [StructuredResponseFake::make()->withStructured(['text' => 'ok'])->withUsage(new Usage(5, 1))]],
    'structured streamed' => ['structured', true, fn () => [TextResponseFake::make()->withText('{"text":"ok"}')->withUsage(new Usage(5, 1))]],
    'structured streamed fallback' => ['structured', true, fn () => [
        TextResponseFake::make()->withText('not json')->withUsage(new Usage(5, 1)),
        StructuredResponseFake::make()->withStructured(['text' => 'ok'])->withUsage(new Usage(5, 1)),
    ]],
    'tools' => ['tools', false, fn () => [maxTokensText()]],
    'tools streamed' => ['tools', true, fn () => [maxTokensText()]],
    'agent' => ['agent', false, fn () => [maxTokensText()]],
    'withMcp' => ['withMcp', false, fn () => [maxTokensText()]],
]);

it('covers both requests of the streamed structured fallback', function () {
    expect(maxTokensRecorded('structured', true, 20, [
        TextResponseFake::make()->withText('not json')->withUsage(new Usage(5, 1)),
        StructuredResponseFake::make()->withStructured(['text' => 'ok'])->withUsage(new Usage(5, 1)),
    ]))->toBe([20, 20]);
});
