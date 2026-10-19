<?php

use Illuminate\Support\Facades\Event;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\Testing\ImageResponseFake;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

// Structured output
class StructuredTestNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $result = $this->llm()->prompt('Analyze this')->schema(new StringSchema('result', 'analysis'))->structured();

        return $state->set('analysis', $result);
    }
}

// A single-step tool call
class ToolsTestNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $tools = []; // Empty tools for test
        $result = $this->llm()->prompt('Use a tool')->tools($tools)->run();

        return $state->set('tool_calls', $result);
    }
}

// A multi-step tool run
class AgentTestNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $tools = [];

        $response = $this->llm()->prompt('Use a tool')->tools($tools)->toolChoice(ToolChoice::Any)->maxSteps(5)->run();

        return $state->set('text', $response->text);
    }
}

class EmbeddingsTokenNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $this->embeddingsFromInput('Track tokens');

        return $state;
    }
}

class ImageTokenNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $this->generateImage('Generate', [Image::fromUrl('https://example.com/image.png')]);

        return $state;
    }
}

class AudioTokenNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $this->audioToText(Audio::fromUrl('https://example.com/audio.mp3'));

        return $state;
    }
}

it('emits TokensUsed event from structured output with callType', function () {
    Event::fake();
    config()->set('agenticgraph.prism.cache_enabled', false);

    // Create a fake structured response with usage
    $fake = StructuredResponseFake::make()
        ->withStructured(['result' => 'test analysis'])
        ->withUsage(new Usage(50, 30))
        ->withMeta(new Meta('id', 'model'));

    Prism::fake([$fake]);

    $node = new StructuredTestNode;
    $result = $node->execute(new State);

    Event::assertDispatched(TokensUsed::class, function (TokensUsed $e) {
        return $e->callType === 'structured'
            && $e->promptTokens === 50
            && $e->completionTokens === 30
            && $e->totalTokens === 80
            && $e->model !== null
            && $e->durationMs !== null;
    });
});

it('emits TokensUsed event from a tool call with callType', function () {
    Event::fake();
    config()->set('agenticgraph.prism.cache_enabled', false);

    $fake = TextResponseFake::make()
        ->withText('I will use the tool')
        ->withToolCalls([])
        ->withUsage(new Usage(25, 15))
        ->withMeta(new Meta('id', 'model'));

    Prism::fake([$fake]);

    $node = new ToolsTestNode;
    $result = $node->execute(new State);

    Event::assertDispatched(TokensUsed::class, function (TokensUsed $e) {
        return $e->callType === 'tools'
            && $e->promptTokens === 25
            && $e->completionTokens === 15
            && $e->totalTokens === 40;
    });
});

it('emits TokensUsed event from a multi-step tool run with callType tools', function () {
    Event::fake();
    config()->set('agenticgraph.prism.cache_enabled', false);

    $fake = TextResponseFake::make()
        ->withText('Agent answer')
        ->withUsage(new Usage(40, 60))
        ->withMeta(new Meta('id', 'model'));

    Prism::fake([$fake]);

    $node = new AgentTestNode;
    $node->execute(new State);

    Event::assertDispatched(TokensUsed::class, function (TokensUsed $e) {
        return $e->callType === 'tools'
            && $e->promptTokens === 40
            && $e->completionTokens === 60
            && $e->totalTokens === 100;
    });
});

it('emits TokensUsed event from embeddings helper', function () {
    Event::fake();
    config()->set('agenticgraph.prism.cache_enabled', false);

    $fake = EmbeddingsResponseFake::make()
        ->withUsage(new EmbeddingsUsage(24));
    Prism::fake([$fake]);

    $node = new EmbeddingsTokenNode;
    $node->execute(new State);

    Event::assertDispatched(TokensUsed::class, function (TokensUsed $e) {
        return $e->callType === 'embeddings'
            && $e->promptTokens === 24
            && $e->completionTokens === 0
            && $e->totalTokens === 24;
    });
});

it('emits TokensUsed event from image generation helper', function () {
    Event::fake();
    config()->set('agenticgraph.prism.cache_enabled', false);

    $fake = ImageResponseFake::make()
        ->withImages([new GeneratedImage('https://example.com/fake.png', null)])
        ->withUsage(new Usage(12, 3))
        ->withMeta(new Meta('id', 'model'));
    Prism::fake([$fake]);

    $node = new ImageTokenNode;
    $node->execute(new State);

    Event::assertDispatched(TokensUsed::class, function (TokensUsed $e) {
        return $e->callType === 'image'
            && $e->promptTokens === 12
            && $e->completionTokens === 3
            && $e->totalTokens === 15;
    });
});

it('emits TokensUsed event from audio transcription helper', function () {
    Event::fake();
    config()->set('agenticgraph.prism.cache_enabled', false);

    $fake = new TextResponse('hi', new Usage(7, 2));
    Prism::fake([$fake]);

    $node = new AudioTokenNode;
    $node->execute(new State);

    Event::assertDispatched(TokensUsed::class, function (TokensUsed $e) {
        return $e->callType === 'audio'
            && $e->promptTokens === 7
            && $e->completionTokens === 2
            && $e->totalTokens === 9;
    });
});

it('emits no TokensUsed for a duration-billed transcription (Prism 0.102 reports null usage)', function () {
    Event::fake();
    config()->set('agenticgraph.prism.cache_enabled', false);

    Prism::fake([new TextResponse('hi', null)]);

    $node = new AudioTokenNode;
    $node->execute(new State);

    Event::assertNotDispatched(TokensUsed::class);
});

it('TokensUsed event has backward compatible defaults', function () {
    $event = new TokensUsed(
        node: 'TestNode',
        promptTokens: 10,
        completionTokens: 20,
        totalTokens: 30
    );

    // Verify defaults for new optional fields
    expect($event->callType)->toBe('text');
    expect($event->model)->toBeNull();
    expect($event->durationMs)->toBeNull();
    expect($event->endedAt)->toBeNull();
});

it('TokensUsed event accepts all new fields', function () {
    $endedAt = new DateTimeImmutable;
    $event = new TokensUsed(
        node: 'TestNode',
        promptTokens: 10,
        completionTokens: 20,
        totalTokens: 30,
        callType: 'structured',
        model: 'openai:gpt-4o',
        durationMs: 150.5,
        endedAt: $endedAt
    );

    expect($event->callType)->toBe('structured');
    expect($event->model)->toBe('openai:gpt-4o');
    expect($event->durationMs)->toBe(150.5);
    expect($event->endedAt)->toBe($endedAt);
});
