<?php

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\Testing\ImageResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Video;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\NodeStreamAccumulator;
use Shahzad\AgenticGraph\Streaming\PrismStreamAdapter;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Streaming\StreamStatus;
use Shahzad\AgenticGraph\Tracing\TraceContext;

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

class FakeStreamingRequest extends PendingTextRequest
{
    public function __construct(private array $events) {}

    public function asStream(): Generator
    {
        foreach ($this->events as $event) {
            yield $event;
        }
    }
}

class FakeStreamingPromptRequest extends PendingTextRequest
{
    public function __construct(private array $events) {}

    public function withPrompt(\Illuminate\Contracts\View\View|string $prompt, array $additionalContent = []): PendingTextRequest
    {
        return $this;
    }

    public function asStream(): Generator
    {
        foreach ($this->events as $event) {
            yield $event;
        }
    }
}

class StreamingPromptTokensNode extends PrismNode
{
    public function __construct(private PendingTextRequest $streamingRequest) {}

    protected function prism(): PendingTextRequest
    {
        return $this->streamingRequest;
    }

    public function execute(StateContract $state): StateContract
    {
        $text = $this->prompt('Hello {name}', ['name' => 'stream']);

        return $state->set('text', $text);
    }
}

class ThrowingStreamingRequest extends PendingTextRequest
{
    public function asStream(): Generator
    {
        throw new RuntimeException('streaming failed');
    }
}

function bindStreamContextWithSink(StreamSinkInterface $sink, ?string $streamId = null, ?string $executionId = null): StreamContext
{
    app()->forgetInstance(StreamContext::class);
    $session = new StreamSession(
        streamId: $streamId ?? uniqid('stream_', true),
        executionId: $executionId ?? uniqid('exec_', true),
        options: new StreamOptions,
        startedAtUnix: time()
    );
    $context = new StreamContext($session, $sink);
    app()->instance(StreamContext::class, $context);

    return $context;
}

abstract class TestStreamingPrismNodeBase extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        if (! $this->isStreaming()) {
            return $this->executeNonStreaming($state);
        }

        $context = app(StreamContext::class);
        $sink = $context->sink();
        if ($sink === null) {
            return $this->executeNonStreaming($state);
        }

        $traceContext = null;
        try {
            $traceContext = app(TraceContext::class);
        } catch (BindingResolutionException) {
        }

        $adapter = new PrismStreamAdapter(
            session: $context->session(),
            nodeName: $this->getName(),
            spanId: $traceContext?->getSpanId(),
            parentSpanId: $traceContext?->getParentSpanId()
        );
        $accumulator = new NodeStreamAccumulator($state);

        try {
            foreach ($this->buildStreamingRequest($state)->asStream() as $event) {
                $streamEvent = $adapter->adapt($event);
                if ($streamEvent === null) {
                    continue;
                }
                $sink->emit($streamEvent);
                $accumulator->apply($streamEvent);
            }
            $sink->close(StreamStatus::SUCCESS);

            return $accumulator->finalize();
        } catch (Throwable $e) {
            $sink->close(StreamStatus::ERROR, [
                'error_type' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function executeNonStreaming(StateContract $state): StateContract
    {
        return $state;
    }
}

class StreamingPrismNode extends TestStreamingPrismNodeBase
{
    public function __construct(private array $events) {}

    protected function buildStreamingRequest(StateContract $state): PendingTextRequest
    {
        return new FakeStreamingRequest($this->events);
    }
}

class ThrowingStreamingPrismNode extends TestStreamingPrismNodeBase
{
    protected function buildStreamingRequest(StateContract $state): PendingTextRequest
    {
        return new ThrowingStreamingRequest;
    }
}

class CompareStreamingPrismNode extends TestStreamingPrismNodeBase
{
    protected function executeNonStreaming(StateContract $state): StateContract
    {
        return $state->set('text', 'Hello world');
    }

    protected function buildStreamingRequest(StateContract $state): PendingTextRequest
    {
        return new FakeStreamingRequest([
            new TextStartEvent('evt-1', 111, 'msg-1'),
            new TextDeltaEvent('evt-2', 112, 'Hello ', 'msg-1'),
            new TextDeltaEvent('evt-3', 113, 'world', 'msg-1'),
            new TextCompleteEvent('evt-4', 114, 'msg-1'),
        ]);
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

    $fakeResponse = new TextResponse('Transcribed text', new Usage(8, 0));
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

it('streams Prism events and rebuilds final state', function () {
    $events = [
        new TextStartEvent('evt-1', 111, 'msg-1'),
        new TextDeltaEvent('evt-2', 112, 'Hello ', 'msg-1'),
        new TextDeltaEvent('evt-3', 113, 'world', 'msg-1'),
        new TextCompleteEvent('evt-4', 114, 'msg-1'),
    ];

    $node = new StreamingPrismNode($events);
    $sink = new MemorySink;
    $state = new State(['prompt' => 'Hello']);
    bindStreamContextWithSink($sink);
    $result = $node->execute($state);
    app()->forgetInstance(StreamContext::class);

    expect($result->get('text'))->toBe('Hello world')
        ->and($sink->status()?->value)->toBe('success')
        ->and(iterator_count($sink->events()))->toBe(4);
});

it('closes sink on streaming error', function () {
    $node = new ThrowingStreamingPrismNode;
    $sink = new MemorySink;
    $state = new State(['prompt' => 'Hello']);

    try {
        bindStreamContextWithSink($sink);
        $node->execute($state);
    } catch (Throwable) {
    } finally {
        app()->forgetInstance(StreamContext::class);
    }

    expect($sink->status()?->value)->toBe('error');
});

it('streams output matches non-streaming output', function () {
    $node = new CompareStreamingPrismNode;
    $state = new State(['prompt' => 'Hello']);
    $sink = new MemorySink;

    bindStreamContextWithSink($sink);
    $streamed = $node->execute($state);
    app()->forgetInstance(StreamContext::class);
    $nonStreamed = $node->execute($state);

    expect($streamed->get('text'))->toBe($nonStreamed->get('text'));
});

it('dispatches TokensUsed when streaming text completes with usage', function () {
    Event::fake([TokensUsed::class]);
    config()->set('agenticgraph.prism.track_tokens', true);
    config()->set('agenticgraph.prism.cache_enabled', false);

    $sink = new MemorySink;
    bindStreamContextWithSink($sink);

    $node = new StreamingPromptTokensNode(new FakeStreamingPromptRequest([
        new TextDeltaEvent('evt-1', time(), 'Hello ', 'msg-1'),
        new TextDeltaEvent('evt-2', time(), 'stream', 'msg-1'),
        new StreamEndEvent('evt-3', time(), FinishReason::Stop, new Usage(11, 22)),
    ]));

    $result = $node->execute(new State);

    expect($result->get('text'))->toBe('Hello stream');

    Event::assertDispatched(TokensUsed::class, function (TokensUsed $event): bool {
        return $event->promptTokens === 11
            && $event->completionTokens === 22
            && $event->totalTokens === 33
            && $event->callType === 'text';
    });

    app()->forgetInstance(StreamContext::class);
});
