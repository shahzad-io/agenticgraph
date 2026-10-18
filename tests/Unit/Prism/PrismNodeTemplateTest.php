<?php

use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;
use Shahzad\AgenticGraph\Prompts\PromptTemplate;
use Shahzad\AgenticGraph\State\State;

class PromptTemplatePrismNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $template = PromptTemplate::create('Summarize {topic} in one word.');
        $text = $this->llm()->prompt($template)->vars([
            'topic' => (string) $state->get('topic', 'testing'),
        ])->text();

        return $state->set('text', $text);
    }
}

class ChatPromptPrismNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $template = ChatPromptTemplate::create()
            ->system('You classify sentiment in one word.')
            ->user('I love this!')
            ->assistant('positive')
            ->user('Classify: {text}');

        $text = $this->llm()->chat($template)->vars([
            'text' => (string) $state->get('text', 'neutral example'),
        ])->text();

        return $state->set('text', $text);
    }
}

class StructuredTemplatePrismNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $prompt = PromptTemplate::create('Classify sentiment of: {text}');

        $schema = new ObjectSchema(
            name: 'sentiment',
            description: 'Sentiment classification',
            properties: [new StringSchema('label', 'positive, negative, or neutral')],
            requiredFields: ['label'],
        );

        $structured = $this->llm()->prompt($prompt)->vars(['text' => (string) $state->get('text', '')])->schema($schema)->structured();

        return $state->set('label', $structured['label'] ?? null);
    }
}

class InterpolatingPromptPrismNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('text', $this->llm()->prompt('Hello {name}')->vars(['name' => $state->get('name')])->text());
    }
}

class MissingPromptVariablePrismNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $this->llm()->prompt('Hello {name}')->text();

        return $state;
    }
}

it('throws when prompt is missing a template variable', function () {
    $node = new MissingPromptVariablePrismNode;

    $node->execute(new State);
})->throws(TemplateException::class, 'Missing template variable: name');

it('prompt still interpolates supplied variables', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    Prism::fake([
        TextResponseFake::make()->withText('Hello John')->withUsage(new Usage(3, 4)),
    ]);

    $node = new InterpolatingPromptPrismNode;
    $result = $node->execute(new State(['name' => 'John']));

    expect($result->get('text'))->toContain('Hello John');
});

it('a prompt template renders and calls the LLM via Prism fake', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    $prismFake = Prism::fake([
        TextResponseFake::make()->withText('concise')->withUsage(new Usage(5, 2)),
    ]);

    $node = new PromptTemplatePrismNode;
    $result = $node->execute(new State(['topic' => 'agent workflows']));

    expect($result->get('text'))->toBe('concise');

    $prismFake->assertRequest(function (array $recorded): void {
        $request = $recorded[0];
        expect($request)->toBeInstanceOf(TextRequest::class);
        expect($request->prompt())->toBe('Summarize agent workflows in one word.');
    });
});

it('a chat template sends native Prism messages instead of a flattened prompt string', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    $prismFake = Prism::fake([
        TextResponseFake::make()->withText('positive')->withUsage(new Usage(8, 1)),
    ]);

    $node = new ChatPromptPrismNode;
    $result = $node->execute(new State(['text' => 'This is amazing!']));

    expect($result->get('text'))->toBe('positive');

    $prismFake->assertRequest(function (array $recorded): void {
        $request = $recorded[0];
        expect($request)->toBeInstanceOf(TextRequest::class);
        expect($request->prompt())->toBeNull();

        $messages = $request->messages();
        expect($messages)->toHaveCount(4);
        expect($messages[0])->toBeInstanceOf(SystemMessage::class);
        expect($messages[1])->toBeInstanceOf(UserMessage::class);
        expect($messages[2])->toBeInstanceOf(AssistantMessage::class);
        expect($messages[3])->toBeInstanceOf(UserMessage::class)
            ->and($messages[3]->content)->toBe('Classify: This is amazing!');
    });
});

it('structured output accepts a prompt template', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['label' => 'positive'])
            ->withUsage(new Usage(4, 2))
            ->withMeta(new Meta('fake-structured-template', 'fake-model')),
    ]);

    $node = new StructuredTemplatePrismNode;
    $result = $node->execute(new State(['text' => 'I love this product!']));

    expect($result->get('label'))->toBe('positive');
});
