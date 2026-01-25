<?php

use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\Testing\ImageResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Usage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Video;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

class TestPrismNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $text = $this->prompt('Hello {name}', ['name' => $state->get('name', 'world')]);

        return $state->set('text', $text);
    }
}

class MessagePrismNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $messages = [new UserMessage('Hello')];
        $text = $this->promptMessages($messages);

        return $state->set('text', $text);
    }
}

class EmbeddingsPrismNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $response = $this->embeddingsFromInput('Hello world');

        return $state->set('tokens', $response->usage?->tokens);
    }
}

class ImagePrismNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $response = $this->generateImage(
            'A scenic mountain landscape',
            [Image::fromUrl('https://example.com/image.png')]
        );

        return $state->set('image_url', $response->firstImage()?->url);
    }
}

class AudioPrismNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $response = $this->audioToText(Audio::fromUrl('https://example.com/audio.mp3'));

        return $state->set('text', $response->text);
    }
}

class VideoPrismNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $text = $this->prompt(
            'Summarize this video',
            [],
            [Video::fromUrl('https://example.com/video.mp4')]
        );

        return $state->set('text', $text);
    }
}

it('uses Prism fake to generate text', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }
    $fakeResponse = TextResponseFake::make()->withText('Hello John')->withUsage(new Usage(10, 20));
    Prism::fake([$fakeResponse]);
    $node = new TestPrismNode;
    $state = new State(['name' => 'John']);
    $result = $node->execute($state);
    expect($result->get('text'))->toContain('Hello John');
});

it('supports message-based prompts with Prism messages', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    $fakeResponse = TextResponseFake::make()->withText('Hello there')->withUsage(new Usage(5, 7));
    Prism::fake([$fakeResponse]);
    $node = new MessagePrismNode;
    $state = new State;
    $result = $node->execute($state);

    expect($result->get('text'))->toContain('Hello there');
});

it('supports embeddings helper with Prism fake', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    $fakeResponse = EmbeddingsResponseFake::make()
        ->withUsage(new EmbeddingsUsage(42));
    Prism::fake([$fakeResponse]);
    $node = new EmbeddingsPrismNode;
    $result = $node->execute(new State);

    expect($result->get('tokens'))->toBe(42);
});

it('supports image generation helper with Prism fake', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    $fakeResponse = ImageResponseFake::make()
        ->withImages([new GeneratedImage('https://example.com/mock.png', null)])
        ->withUsage(new Usage(12, 0));
    Prism::fake([$fakeResponse]);
    $node = new ImagePrismNode;
    $result = $node->execute(new State);

    expect($result->get('image_url'))->toBe('https://example.com/mock.png');
});

it('supports audio transcription helper with Prism fake', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    $fakeResponse = new \Prism\Prism\Audio\TextResponse('Transcribed text', new Usage(8, 0));
    Prism::fake([$fakeResponse]);
    $node = new AudioPrismNode;
    $result = $node->execute(new State);

    expect($result->get('text'))->toBe('Transcribed text');
});

it('supports video media prompts with Prism fake', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    $fakeResponse = TextResponseFake::make()->withText('Video summary')->withUsage(new Usage(9, 3));
    Prism::fake([$fakeResponse]);
    $node = new VideoPrismNode;
    $result = $node->execute(new State);

    expect($result->get('text'))->toBe('Video summary');
});
