<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Nodes;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\PendingRequest as PendingAudioRequest;
use Prism\Prism\Audio\TextResponse as AudioTextResponse;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Prism\Prism\Images\PendingRequest as PendingImageRequest;
use Prism\Prism\Images\Response as ImageResponse;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Prism\Prism\ValueObjects\Media\Audio as AudioMedia;
use Prism\Relay\Facades\Relay;
use Shahzad\AgenticGraph\Contracts\PrismClient;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Events\ToolCallCaptured;
use Shahzad\AgenticGraph\Mcp\McpRequest;
use Shahzad\AgenticGraph\Nodes\Contracts\StreamableNodeInterface;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\PromptTemplate;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\NodeStreamAccumulator;
use Shahzad\AgenticGraph\Streaming\PrismStreamAdapter;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Streaming\StreamStatus;
use Shahzad\AgenticGraph\Tracing\TraceContext;

/**
 * Abstract node with integrated PrismPHP AI capabilities.
 *
 * @method PendingTextRequest prism() Create a text generation request
 * @method PendingEmbeddingsRequest embeddings() Create an embeddings request
 * @method PendingImageRequest image() Create an image generation request
 * @method PendingAudioRequest audio() Create an audio processing request
 * @method string prompt(string $template, array<string, mixed> $variables = [], array<int, \Prism\Prism\ValueObjects\Media\Media|\Prism\Prism\ValueObjects\Media\Text> $additionalContent = []) Generate text from a template
 * @method string promptMessages(array<int, Message> $messages) Generate text from message array
 * @method EmbeddingsResponse embeddingsFromInput(string|array<int, string> $inputs, array<string, mixed> $providerOptions = []) Generate embeddings from input
 * @method ImageResponse generateImage(string $prompt, array<int, \Prism\Prism\ValueObjects\Media\Media|\Prism\Prism\ValueObjects\Media\Text> $additionalContent = [], array<string, mixed> $providerOptions = []) Generate an image
 * @method AudioTextResponse audioToText(AudioMedia $audio, array<string, mixed> $providerOptions = []) Transcribe audio to text
 * @method AudioResponse textToAudio(string $text, ?string $voice = null, array<string, mixed> $providerOptions = []) Convert text to audio
 * @method mixed structured(string $prompt, mixed $schema) Generate structured output using a schema
 * @method array<int, \Prism\Prism\ValueObjects\ToolCall> tools(string $prompt, array<int, \Prism\Prism\Tool> $tools) Execute with tool calling
 * @method McpRequest withMcp(string $serverName, array<string, mixed> $options = []) Create MCP tool request
 * @method static withModel(string $model) Set the model for this node
 * @method static withCache(int $ttlMinutes) Enable caching with TTL
 * @method static withoutCache() Disable caching
 * @method string promptTemplate(PromptTemplate $template, array<string, mixed> $variables = [], array<int, \Prism\Prism\ValueObjects\Media\Media|\Prism\Prism\ValueObjects\Media\Text> $additionalContent = []) Use a prompt template
 * @method string chatPrompt(ChatPromptTemplate $template, array<string, mixed> $variables = [], array<int, \Prism\Prism\ValueObjects\Media\Media|\Prism\Prism\ValueObjects\Media\Text> $additionalContent = []) Use a chat prompt template
 * @method bool shouldInterrupt(StateContract $state) Check if execution should pause
 * @method string getInterruptReason(StateContract $state) Get human-readable interrupt reason
 * @method StateContract onResume(StateContract $state, mixed $humanInput) Process human input when resuming
 * @method StateContract handleApproval(StateContract $state, bool $approved, ?string $comments = null) Handle approval workflows
 */
abstract class PrismNode extends AbstractNode implements StreamableNodeInterface
{
    private ?CacheRepository $cache = null;

    private ?Dispatcher $events = null;

    protected ?PrismClient $prismClient = null;

    protected ?string $model = null;

    protected ?int $cacheTtl = null;

    protected bool $trackTokens = true;

    private bool $suppressAutoTracking = false;

    private bool $wrappedMcpTools = false;

    protected function prism(): PendingTextRequest
    {
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);
        $startTime = microtime(true);
        $timeout = (int) config('agenticgraph.api.timeout', 30);
        $connectTimeout = (int) config('agenticgraph.api.connect_timeout', 10);
        $request = $this->prismClient()->text()
            ->using($provider, $model)
            ->withClientOptions([
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
            ]);
        $request->onComplete(function ($pending, $messages, $response) use ($startTime, $provider, $model) {
            if ($this->suppressAutoTracking) {
                return;
            }
            if (! $this->trackTokens) {
                return;
            }
            $usage = $this->resolveUsage($response);
            if ($usage === null) {
                return;
            }
            $durationMs = (microtime(true) - $startTime) * 1000;
            $callType = 'text';
            $toolCalls = [];
            // @phpstan-ignore-next-line - Defensive coding for different response types
            if (is_object($response) && property_exists($response, 'toolCalls')) {
                $toolCalls = $response->toolCalls ?? [];
                // @phpstan-ignore-next-line - Defensive coding for different response types
            } elseif (is_array($response) && array_key_exists('toolCalls', $response)) {
                // @phpstan-ignore-next-line - Defensive coding for different response types
                $toolCalls = $response['toolCalls'] ?? [];
            }
            // @phpstan-ignore-next-line - Defensive coding for different response types
            if (is_array($toolCalls) && $toolCalls !== []) {
                $callType = 'tools';
                $this->dispatchToolCalls($toolCalls, 'prism');
            }
            $this->events()->dispatch(TokensUsed::fromLlmCall(
                node: $this->getName(),
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $usage['promptTokens'],
                completionTokens: $usage['completionTokens'],
                callType: $callType,
                model: "{$provider}:{$model}",
                prompt: $this->extractPromptFromMessages($messages),
                response: $this->extractResponseText($response)
            ));
        });

        return $request;
    }

    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract
    {
        $traceContext = $this->resolveTraceContext();
        $streamContext = null;
        if (app()->bound(StreamContext::class)) {
            $streamContext = app(StreamContext::class);
        }
        $session = $streamContext?->session() ?? new StreamSession(
            streamId: uniqid('stream_', true),
            executionId: $traceContext?->getExecutionId() ?? uniqid('exec_', true),
            options: new StreamOptions,
            startedAtUnix: time()
        );

        $adapter = new PrismStreamAdapter(
            session: $session,
            nodeName: $this->getName(),
            spanId: $traceContext?->getSpanId(),
            parentSpanId: $traceContext?->getParentSpanId()
        );
        $accumulator = new NodeStreamAccumulator($state);

        try {
            $request = $this->buildStreamingRequest($state);
            foreach ($request->asStream() as $event) {
                $streamEvent = $adapter->adapt($event);
                if ($streamEvent === null) {
                    continue;
                }
                $sink->emit($streamEvent);
                $accumulator->apply($streamEvent);
            }
            $sink->close(StreamStatus::SUCCESS);

            return $accumulator->finalize();
        } catch (\Throwable $e) {
            $sink->close(StreamStatus::ERROR, [
                'error_type' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function buildStreamingRequest(StateContract $state): PendingTextRequest
    {
        $request = $this->prism();
        $messages = $state->get('messages');

        if (is_array($messages) && $messages !== []) {
            $request->withMessages($messages);
        } else {
            $prompt = $state->get('prompt');
            if (! is_string($prompt) || $prompt === '') {
                throw new \RuntimeException('Streaming prompt or messages are required.');
            }
            $additionalContent = $state->get('additional_content', []);
            if (! is_array($additionalContent)) {
                $additionalContent = [];
            }
            $request->withPrompt($prompt, $additionalContent);
        }

        $tools = $state->get('tools');
        if (is_array($tools) && $tools !== []) {
            $request->withTools($tools);
        }

        return $request;
    }

    protected function embeddings(): PendingEmbeddingsRequest
    {
        $embeddingsProvider = config('agenticgraph.prism.embeddings.provider');
        $embeddingsModel = config('agenticgraph.prism.embeddings.model');
        $embeddingsApiKey = config('agenticgraph.prism.embeddings.api_key');
        $hasEmbeddingsProvider = is_string($embeddingsProvider) && $embeddingsProvider !== '';
        $hasEmbeddingsModel = is_string($embeddingsModel) && $embeddingsModel !== '';

        if ($hasEmbeddingsProvider || $hasEmbeddingsModel) {
            if ($hasEmbeddingsProvider && $hasEmbeddingsModel) {
                $provider = $embeddingsProvider;
                $model = $embeddingsModel;
            } elseif ($hasEmbeddingsModel) {
                [$provider, $model] = $this->parseModel($embeddingsModel);
            } else {
                $config = $this->model ?? (string) config('agenticgraph.prism.model');
                [, $model] = $this->parseModel($config);
                $provider = $embeddingsProvider;
            }
        } else {
            $config = $this->model ?? (string) config('agenticgraph.prism.model');
            [$provider, $model] = $this->parseModel($config);
        }

        $providerConfig = [];

        if (is_string($embeddingsApiKey) && $embeddingsApiKey !== '') {
            $providerConfig['api_key'] = $embeddingsApiKey;
        }

        return $this->prismClient()->embeddings()->using($provider, $model, $providerConfig);
    }

    protected function image(): PendingImageRequest
    {
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);

        return $this->prismClient()->image()->using($provider, $model);
    }

    protected function audio(): PendingAudioRequest
    {
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);

        return $this->prismClient()->audio()->using($provider, $model);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<int, \Prism\Prism\ValueObjects\Media\Media|\Prism\Prism\ValueObjects\Media\Text>  $additionalContent
     */
    protected function prompt(string $template, array $variables = [], array $additionalContent = []): string
    {
        $startTime = microtime(true);
        $promptText = $this->interpolate($template, $variables);
        if ($this->streamingEnabled()) {
            $request = $this->prism()->withPrompt($promptText, $additionalContent);

            return $this->streamTextRequest($request);
        }
        $useCache = $this->shouldCache() && $additionalContent === [];
        $cacheKey = $useCache ? $this->getCacheKey($promptText) : null;
        if ($useCache && $this->cache()->has($cacheKey)) {
            return $this->cache()->get($cacheKey);
        }
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);
        $this->suppressAutoTracking = true;
        $response = $this->prism()->withPrompt($promptText, $additionalContent)->asText();
        $this->suppressAutoTracking = false;
        $responseText = $response->text;
        if ($useCache) {
            $this->cache()->put($cacheKey, $responseText, ($this->cacheTtl ?? (int) config('agenticgraph.prism.cache_ttl')) * 60);
        }
        if ($this->trackTokens) {
            $usage = $this->resolveUsage($response);
        }
        if ($this->trackTokens && $usage !== null) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events()->dispatch(TokensUsed::fromLlmCall(
                node: $this->getName(),
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $usage['promptTokens'],
                completionTokens: $usage['completionTokens'],
                callType: 'text',
                model: "{$provider}:{$model}",
                prompt: $promptText,
                response: $responseText
            ));
        }

        return $responseText;
    }

    /**
     * @param  array<int, Message>  $messages
     */
    protected function promptMessages(array $messages): string
    {
        $startTime = microtime(true);
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);

        if ($this->streamingEnabled()) {
            $request = $this->prism()->withMessages($messages);

            return $this->streamTextRequest($request);
        }

        $this->suppressAutoTracking = true;
        $response = $this->prism()
            ->withMessages($messages)
            ->asText();
        $this->suppressAutoTracking = false;

        $responseText = $response->text;

        if ($this->trackTokens) {
            $usage = $this->resolveUsage($response);
        }
        if ($this->trackTokens && $usage !== null) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events()->dispatch(TokensUsed::fromLlmCall(
                node: $this->getName(),
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $usage['promptTokens'],
                completionTokens: $usage['completionTokens'],
                callType: 'text',
                model: "{$provider}:{$model}",
                prompt: $this->extractPromptFromMessages($messages),
                response: $responseText
            ));
        }

        return $responseText;
    }

    /**
     * @param  array<int, string>  $inputs
     * @param  array<string, mixed>  $providerOptions
     */
    protected function embeddingsFromInput(string|array $inputs, array $providerOptions = []): EmbeddingsResponse
    {
        $startTime = microtime(true);
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);
        $request = $this->embeddings();

        if ($providerOptions !== []) {
            $request->withProviderOptions($providerOptions);
        }

        if (is_array($inputs)) {
            $request->fromArray($inputs);
        } else {
            $request->fromInput($inputs);
        }

        $response = $request->asEmbeddings();

        if ($this->trackTokens) {
            $tokens = $response->usage->tokens ?? 0;
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events()->dispatch(TokensUsed::fromLlmCall(
                node: $this->getName(),
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $tokens,
                completionTokens: 0,
                callType: 'embeddings',
                model: "{$provider}:{$model}",
                prompt: is_array($inputs) ? implode("\n---\n", $inputs) : $inputs,
                response: null  // Embeddings don't have text response
            ));
        }

        return $response;
    }

    /**
     * @param  array<int, \Prism\Prism\ValueObjects\Media\Media|\Prism\Prism\ValueObjects\Media\Text>  $additionalContent
     * @param  array<string, mixed>  $providerOptions
     */
    protected function generateImage(string $prompt, array $additionalContent = [], array $providerOptions = []): ImageResponse
    {
        $startTime = microtime(true);
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);
        $request = $this->image()->withPrompt($prompt, $additionalContent);

        if ($providerOptions !== []) {
            $request->withProviderOptions($providerOptions);
        }

        $response = $request->generate();

        if ($this->trackTokens && isset($response->usage)) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events()->dispatch(TokensUsed::fromLlmCall(
                node: $this->getName(),
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $response->usage->promptTokens ?? 0,
                completionTokens: $response->usage->completionTokens ?? 0,
                callType: 'image',
                model: "{$provider}:{$model}",
                prompt: $prompt,
                response: null  // Image responses don't have text
            ));
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     */
    protected function audioToText(AudioMedia $audio, array $providerOptions = []): AudioTextResponse
    {
        $startTime = microtime(true);
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);
        $request = $this->audio()->withInput($audio);

        if ($providerOptions !== []) {
            $request->withProviderOptions($providerOptions);
        }

        $response = $request->asText();

        if ($this->trackTokens && isset($response->usage)) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events()->dispatch(TokensUsed::fromLlmCall(
                node: $this->getName(),
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $response->usage->promptTokens ?? 0,
                completionTokens: $response->usage->completionTokens ?? 0,
                callType: 'audio',
                model: "{$provider}:{$model}",
                prompt: null,  // Audio input, no text prompt
                response: $response->text ?? null
            ));
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     */
    protected function textToAudio(string $text, ?string $voice = null, array $providerOptions = []): AudioResponse
    {
        $request = $this->audio()->withInput($text);

        if ($voice !== null) {
            $request->withVoice($voice);
        }

        if ($providerOptions !== []) {
            $request->withProviderOptions($providerOptions);
        }

        return $request->asAudio();
    }

    protected function structured(string $prompt, mixed $schema): mixed
    {
        $startTime = microtime(true);
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);

        if ($this->streamingEnabled() && $schema instanceof Schema) {
            $promptWithSchema = $this->buildStructuredPrompt($prompt, $schema);
            $text = $this->prompt($promptWithSchema);

            try {
                return $this->decodeStructuredResponseText($text);
            } catch (PrismStructuredDecodingException) {
                $response = $this->prismClient()->structured()
                    ->using($provider, $model)
                    ->withSchema($schema)
                    ->withPrompt($prompt)
                    ->asStructured();

                if ($this->trackTokens) {
                    $usage = $this->resolveUsage($response);
                }
                if ($this->trackTokens && $usage !== null) {
                    $durationMs = (microtime(true) - $startTime) * 1000;
                    $this->events()->dispatch(TokensUsed::fromLlmCall(
                        node: $this->getName(),
                        callStartMicrotime: $startTime,
                        callDurationMs: $durationMs,
                        promptTokens: $usage['promptTokens'],
                        completionTokens: $usage['completionTokens'],
                        callType: 'structured',
                        model: "{$provider}:{$model}",
                        prompt: $prompt,
                        response: $this->extractResponseText($response)
                    ));
                }

                return $response->structured;
            }
        }

        $response = $this->prismClient()->structured()
            ->using($provider, $model)
            ->withSchema($schema)
            ->withPrompt($prompt)
            ->asStructured();

        if ($this->trackTokens) {
            $usage = $this->resolveUsage($response);
        }
        if ($this->trackTokens && $usage !== null) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events()->dispatch(TokensUsed::fromLlmCall(
                node: $this->getName(),
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $usage['promptTokens'],
                completionTokens: $usage['completionTokens'],
                callType: 'structured',
                model: "{$provider}:{$model}",
                prompt: $prompt,
                response: $this->extractResponseText($response)
            ));
        }

        return $response->structured;
    }

    /**
     * @param  array<int, \Prism\Prism\Tool>  $tools
     * @return array<int, \Prism\Prism\ValueObjects\ToolCall>
     */
    protected function tools(string $prompt, array $tools): array
    {
        $startTime = microtime(true);
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);

        if ($this->streamingEnabled()) {
            $request = $this->prism()
                ->withTools($tools)
                ->withPrompt($prompt);
            $context = app(StreamContext::class);
            $sink = $context->sink();
            if ($sink === null) {
                $response = $request->asText();
                $this->dispatchToolCalls($response->toolCalls ?? [], 'prism');

                return $response->toolCalls ?? [];
            }
            $traceContext = $this->resolveTraceContext();
            $adapter = new PrismStreamAdapter(
                session: $context->session(),
                nodeName: $this->getName(),
                spanId: $traceContext?->getSpanId(),
                parentSpanId: $traceContext?->getParentSpanId()
            );
            $toolCalls = [];
            $this->suppressAutoTracking = true;
            try {
                foreach ($request->asStream() as $event) {
                    $streamEvent = $adapter->adapt($event);
                    if ($streamEvent === null) {
                        continue;
                    }
                    $sink->emit($streamEvent);
                    if ($event instanceof ToolCallEvent) {
                        $toolCalls[] = $event->toolCall;
                    }
                }
            } finally {
                $this->suppressAutoTracking = false;
            }
            $this->dispatchToolCalls($toolCalls, 'prism');

            return $toolCalls;
        }

        $this->suppressAutoTracking = true;
        $response = $this->prism()
            ->withTools($tools)
            ->withPrompt($prompt)
            ->asText();
        $this->suppressAutoTracking = false;

        $this->dispatchToolCalls($response->toolCalls ?? [], 'prism');

        if ($this->trackTokens) {
            $usage = $this->resolveUsage($response);
        }
        if ($this->trackTokens && $usage !== null) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events()->dispatch(TokensUsed::fromLlmCall(
                node: $this->getName(),
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $usage['promptTokens'],
                completionTokens: $usage['completionTokens'],
                callType: 'tools',
                model: "{$provider}:{$model}",
                prompt: $prompt,
                response: $this->extractResponseText($response)
            ));
        }

        return $response->toolCalls;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function withMcp(string $serverName, array $options = []): McpRequest
    {
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);

        $tools = $options['tools'] ?? Relay::tools($serverName);
        if (! is_array($tools)) {
            $tools = [];
        }

        $tools = $this->wrapMcpTools($tools);
        $captureToolCalls = ! $this->wrappedMcpTools;
        // Do not use $this->prism() here as it attaches a tracking hook,
        // and McpRequest handles tracking itself.
        $request = $this->prismClient()->text()->using($provider, $model)->withTools($tools);

        $toolChoice = $options['toolChoice'] ?? ToolChoice::Any;
        $request->withToolChoice($toolChoice);

        $maxSteps = $options['maxSteps'] ?? null;
        if (is_int($maxSteps)) {
            $request->withMaxSteps($maxSteps);
        }

        return new McpRequest(
            $request,
            $provider,
            $model,
            $this->getName(),
            $this->trackTokens,
            $this->events(),
            $captureToolCalls
        );
    }

    protected function withModel(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    protected function withCache(int $ttlMinutes): static
    {
        $this->cacheTtl = $ttlMinutes;

        return $this;
    }

    protected function withoutCache(): static
    {
        $this->cacheTtl = 0;

        return $this;
    }

    protected function shouldCache(): bool
    {
        return (bool) config('agenticgraph.prism.cache_enabled') && $this->cacheTtl !== 0;
    }

    protected function getCacheKey(string $prompt): string
    {
        $model = $this->model ?? config('agenticgraph.prism.model', 'default');
        $nodeClass = static::class;
        $version = config('agenticgraph.cache.version', 'v1');

        // Include node class and version to prevent cache key collisions
        return sprintf(
            'agenticgraph:prism:%s:%s:%s',
            $version,
            $nodeClass,
            md5($model.'|'.$prompt)
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    protected function interpolate(string $template, array $variables): string
    {
        return preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($variables) {
            $k = $matches[1];

            return array_key_exists($k, $variables) ? (string) $variables[$k] : $matches[0];
        }, $template);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function parseModel(string $value): array
    {
        $parts = explode(':', $value, 2);
        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        return ['openai', $value];
    }

    protected function trackTokensFromResponse(mixed $response, string $callType, float $startTime): void
    {
        if (! $this->trackTokens) {
            return;
        }

        $usage = $this->resolveUsage($response);
        if ($usage === null) {
            return;
        }

        $durationMs = (microtime(true) - $startTime) * 1000;
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);

        $this->events()->dispatch(TokensUsed::fromLlmCall(
            node: $this->getName(),
            callStartMicrotime: $startTime,
            callDurationMs: $durationMs,
            promptTokens: $usage['promptTokens'],
            completionTokens: $usage['completionTokens'],
            callType: $callType,
            model: "{$provider}:{$model}",
            prompt: null,  // Generic method, no prompt context available
            response: $this->extractResponseText($response)
        ));
    }

    /**
     * @return array{promptTokens: int, completionTokens: int, totalTokens: int}|null
     */
    private function resolveUsage(mixed $response): ?array
    {
        if (is_object($response) && isset($response->usage)) {
            $promptTokens = (int) ($response->usage->promptTokens ?? 0);
            $completionTokens = (int) ($response->usage->completionTokens ?? 0);

            return [
                'promptTokens' => $promptTokens,
                'completionTokens' => $completionTokens,
                'totalTokens' => $promptTokens + $completionTokens,
            ];
        }

        $steps = null;
        if (is_object($response) && property_exists($response, 'steps')) {
            $steps = $response->steps;
        } elseif (is_array($response) && array_key_exists('steps', $response)) {
            $steps = $response['steps'];
        }

        if ($steps instanceof \Illuminate\Support\Collection) {
            $steps = $steps->all();
        }

        if (! is_array($steps)) {
            return null;
        }

        $promptTokens = 0;
        $completionTokens = 0;

        foreach ($steps as $step) {
            $usage = null;
            if (is_object($step) && isset($step->usage)) {
                $usage = $step->usage;
            } elseif (is_array($step) && isset($step['usage'])) {
                $usage = $step['usage'];
            }

            if ($usage === null) {
                continue;
            }

            if (is_object($usage)) {
                $promptTokens += (int) ($usage->promptTokens ?? 0);
                $completionTokens += (int) ($usage->completionTokens ?? 0);
            } elseif (is_array($usage)) {
                $promptTokens += (int) ($usage['promptTokens'] ?? 0);
                $completionTokens += (int) ($usage['completionTokens'] ?? 0);
            }
        }

        if ($promptTokens === 0 && $completionTokens === 0) {
            return null;
        }

        return [
            'promptTokens' => $promptTokens,
            'completionTokens' => $completionTokens,
            'totalTokens' => $promptTokens + $completionTokens,
        ];
    }

    /**
     * Extract prompt text from messages (array or Collection).
     *
     * @param  array<int, Message>|\Illuminate\Support\Collection<int, Message>|null  $messages
     */
    private function extractPromptFromMessages(array|\Illuminate\Support\Collection|null $messages): ?string
    {
        if ($messages === null) {
            return null;
        }

        // Convert Collection to array if needed
        if ($messages instanceof \Illuminate\Support\Collection) {
            $messages = $messages->all();
        }

        if ($messages === []) {
            return null;
        }

        $parts = [];
        foreach ($messages as $message) {
            $content = null;

            if (method_exists($message, 'getContent')) {
                $content = $message->getContent();
            } elseif (method_exists($message, 'content')) {
                $content = $message->content();
            } elseif (property_exists($message, 'content')) {
                $content = $message->content;
            } elseif (method_exists($message, 'text')) {
                $content = $message->text();
            } elseif (property_exists($message, 'text')) {
                $content = $message->text;
            }

            if (is_string($content) && $content !== '') {
                $role = 'user';
                if (method_exists($message, 'getRole')) {
                    $role = $message->getRole();
                } elseif (method_exists($message, 'role')) {
                    $role = $message->role();
                } elseif (property_exists($message, 'role')) {
                    $role = $message->role;
                }

                $parts[] = "[{$role}] {$content}";
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * Extract response text from a response object.
     */
    private function extractResponseText(mixed $response): ?string
    {
        if (! is_object($response)) {
            return null;
        }

        // Try different common properties
        if (property_exists($response, 'text')) {
            return $response->text;
        }

        if (property_exists($response, 'content')) {
            return $response->content;
        }

        if (property_exists($response, 'message')) {
            return is_string($response->message) ? $response->message : null;
        }

        // Try to get structured output as JSON
        if (property_exists($response, 'structured')) {
            $encoded = json_encode($response->structured);

            return $encoded !== false ? $encoded : null;
        }

        return null;
    }

    private function resolveTraceContext(): ?TraceContext
    {
        try {
            return app(TraceContext::class);
        } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
            return null;
        }
    }

    private function streamingEnabled(): bool
    {
        try {
            $context = app(StreamContext::class);
        } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
            return false;
        }

        return $context->sink() !== null;
    }

    private function streamTextRequest(PendingTextRequest $request): string
    {
        try {
            $context = app(StreamContext::class);
        } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
            return $request->asText()->text;
        }

        $sink = $context->sink();
        if ($sink === null) {
            return $request->asText()->text;
        }

        $traceContext = $this->resolveTraceContext();
        $adapter = new PrismStreamAdapter(
            session: $context->session(),
            nodeName: $this->getName(),
            spanId: $traceContext?->getSpanId(),
            parentSpanId: $traceContext?->getParentSpanId()
        );
        $text = '';

        $this->suppressAutoTracking = true;
        try {
            foreach ($request->asStream() as $event) {
                $streamEvent = $adapter->adapt($event);
                if ($streamEvent === null) {
                    continue;
                }
                $sink->emit($streamEvent);
                if ($streamEvent->type() === 'text_delta') {
                    $delta = $streamEvent->payload()['delta'] ?? '';
                    if (is_string($delta) && $delta !== '') {
                        $text .= $delta;
                    }
                }
            }
        } finally {
            $this->suppressAutoTracking = false;
        }

        return $text;
    }

    private function buildStructuredPrompt(string $prompt, Schema $schema): string
    {
        $schemaJson = json_encode($schema->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($schemaJson === false) {
            $schemaJson = '{}';
        }

        return $prompt."\n\nReturn only JSON that matches this schema:\n".$schemaJson."\nNo markdown, no extra keys.";
    }

    private function decodeStructuredResponseText(string $responseText): mixed
    {
        $pattern = '/^```(?:json)?\s*\n?(.*?)\n?```$/s';
        $payload = trim($responseText);
        if (preg_match($pattern, $payload, $matches)) {
            $payload = trim($matches[1]);
        }

        try {
            return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw PrismStructuredDecodingException::make($responseText);
        }
    }

    /**
     * @param  array<int, mixed>  $toolCalls
     */
    private function dispatchToolCalls(array $toolCalls, string $toolType): void
    {
        if ($toolCalls === []) {
            return;
        }

        $traceContext = null;
        try {
            $traceContext = app(TraceContext::class);
        } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
            // TraceContext not bound, tracing not active
            return;
        }

        $executionId = $traceContext->getExecutionId();
        $spanId = $traceContext->getSpanId();

        foreach ($toolCalls as $toolCall) {
            $toolName = null;
            $toolCallId = null;
            $arguments = null;

            if (is_object($toolCall)) {
                $toolName = $toolCall->name ?? null;
                $toolCallId = $toolCall->id ?? null;
                if (method_exists($toolCall, 'arguments')) {
                    $arguments = $toolCall->arguments();
                } elseif (property_exists($toolCall, 'arguments')) {
                    $arguments = $toolCall->arguments;
                }
            } elseif (is_array($toolCall)) {
                $toolName = $toolCall['name'] ?? null;
                $toolCallId = $toolCall['id'] ?? null;
                $arguments = $toolCall['arguments'] ?? null;
            }

            if (! is_string($toolName) || $toolName === '') {
                continue;
            }

            $this->events()->dispatch(new ToolCallCaptured(
                executionId: $executionId,
                spanId: $spanId,
                toolName: $toolName,
                toolCallId: is_string($toolCallId) ? $toolCallId : null,
                toolType: $toolType,
                toolProvider: $this->toolProviderFromName($toolName),
                inputSize: $this->payloadSize($arguments),
                outputSize: null,
                durationMs: null,
                startedAt: new \DateTimeImmutable,
                toolRequestId: is_string($toolCallId) ? $toolCallId : null,
                status: 'success',
                errorType: null,
                errorMessage: null
            ));
        }
    }

    /**
     * @param  array<int, mixed>  $tools
     * @return array<int, \Prism\Prism\Tool>
     */
    private function wrapMcpTools(array $tools): array
    {
        $wrapped = [];
        $wrappedTools = false;

        foreach ($tools as $tool) {
            if (! $tool instanceof \Prism\Prism\Tool) {
                $wrapped[] = $tool;

                continue;
            }

            $wrappedTool = new \Prism\Prism\Tool;
            $toolName = $tool->name();
            $wrappedTool->as($toolName);
            $wrappedTool->for($tool->description());

            foreach ($tool->parameters() as $name => $schema) {
                $required = in_array($name, $tool->requiredParameters(), true);
                $wrappedTool->withParameter($schema, $required);
            }

            $wrappedTool->using(function (...$args) use ($tool, $toolName): string {
                $startedAt = new \DateTimeImmutable;
                $start = microtime(true);
                try {
                    $result = $tool->handle(...$args);
                } catch (\Throwable $e) {
                    $durationMs = (microtime(true) - $start) * 1000;
                    $this->dispatchMcpToolSpan($toolName, $args, null, $durationMs, 'failed', get_class($e), $e->getMessage(), $startedAt);
                    throw $e;
                }

                $durationMs = (microtime(true) - $start) * 1000;
                [$status, $errorType, $errorMessage] = $this->detectToolError($result);
                $this->dispatchMcpToolSpan($toolName, $args, $result, $durationMs, $status, $errorType, $errorMessage, $startedAt);

                return $result;
            });

            $wrapped[] = $wrappedTool;
            $wrappedTools = true;
        }

        $this->wrappedMcpTools = $wrappedTools;

        return $wrapped;
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function detectToolError(string $result): array
    {
        if (! str_starts_with($result, 'ERROR:') && ! str_starts_with($result, 'Tool execution error:')) {
            return ['success', null, null];
        }

        $message = trim($result);
        $type = 'tool_error';
        $lower = strtolower($message);
        if (str_contains($lower, 'transport') || str_contains($lower, 'connection') || str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            $type = 'connection';
        }

        return ['failed', $type, $message];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function dispatchMcpToolSpan(
        string $toolName,
        array $args,
        ?string $result,
        float $durationMs,
        string $status,
        ?string $errorType,
        ?string $errorMessage,
        \DateTimeImmutable $startedAt
    ): void {
        $traceContext = null;
        try {
            $traceContext = app(TraceContext::class);
        } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
            // TraceContext not bound, tracing not active
            return;
        }

        $this->events()->dispatch(new ToolCallCaptured(
            executionId: $traceContext->getExecutionId(),
            spanId: $traceContext->getSpanId(),
            toolName: $toolName,
            toolCallId: null,
            toolType: 'mcp',
            inputSize: $this->payloadSize($args),
            outputSize: $this->payloadSize($result),
            durationMs: $durationMs,
            startedAt: $startedAt,
            toolProvider: $this->toolProviderFromName($toolName),
            toolRequestId: null,
            status: $status,
            errorType: $errorType,
            errorMessage: $errorMessage
        ));
    }

    private function toolProviderFromName(string $toolName): ?string
    {
        if (! str_contains($toolName, '__')) {
            return null;
        }

        $parts = explode('__', $toolName);
        $provider = $parts[1] ?? null;

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    private function payloadSize(mixed $payload): ?int
    {
        if ($payload === null) {
            return null;
        }

        if (is_string($payload)) {
            return strlen($payload);
        }

        $encoded = json_encode($payload);
        if (! is_string($encoded)) {
            return null;
        }

        return strlen($encoded);
    }

    /**
     * Use a prompt template
     *
     * @param  array<string, mixed>  $variables
     * @param  array<int, \Prism\Prism\ValueObjects\Media\Media|\Prism\Prism\ValueObjects\Media\Text>  $additionalContent
     */
    protected function promptTemplate(PromptTemplate $template, array $variables = [], array $additionalContent = []): string
    {
        $startTime = microtime(true);
        $promptText = $template->format($variables);
        if ($this->streamingEnabled()) {
            $request = $this->prism()->withPrompt($promptText, $additionalContent);

            return $this->streamTextRequest($request);
        }
        $useCache = $this->shouldCache() && $additionalContent === [];
        $cacheKey = $useCache ? $this->getCacheKey($promptText) : null;

        if ($useCache && $this->cache()->has($cacheKey)) {
            return $this->cache()->get($cacheKey);
        }

        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);

        $this->suppressAutoTracking = true;
        $response = $this->prism()->withPrompt($promptText, $additionalContent)->asText();
        $this->suppressAutoTracking = false;

        $responseText = $response->text;

        if ($useCache) {
            $this->cache()->put($cacheKey, $responseText, ($this->cacheTtl ?? (int) config('agenticgraph.prism.cache_ttl')) * 60);
        }

        if ($this->trackTokens && isset($response->usage)) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events()->dispatch(TokensUsed::fromLlmCall(
                node: $this->getName(),
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $response->usage->promptTokens ?? 0,
                completionTokens: $response->usage->completionTokens ?? 0,
                callType: 'text',
                model: "{$provider}:{$model}",
                prompt: $promptText,
                response: $responseText
            ));
        }

        return $responseText;
    }

    /**
     * Use a chat prompt template
     *
     * @param  array<string, mixed>  $variables
     * @param  array<int, \Prism\Prism\ValueObjects\Media\Media|\Prism\Prism\ValueObjects\Media\Text>  $additionalContent
     */
    protected function chatPrompt(ChatPromptTemplate $template, array $variables = [], array $additionalContent = []): string
    {
        $startTime = microtime(true);
        $promptText = $template->formatAsString($variables);
        if ($this->streamingEnabled()) {
            $request = $this->prism()->withPrompt($promptText, $additionalContent);

            return $this->streamTextRequest($request);
        }

        $useCache = $this->shouldCache() && $additionalContent === [];
        $cacheKey = $useCache ? $this->getCacheKey($promptText) : null;

        if ($useCache && $this->cache()->has($cacheKey)) {
            return $this->cache()->get($cacheKey);
        }

        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);
        $response = $this->prism()->withPrompt($promptText, $additionalContent)->asText();
        $responseText = $response->text;

        if ($useCache) {
            $this->cache()->put($cacheKey, $responseText, ($this->cacheTtl ?? (int) config('agenticgraph.prism.cache_ttl')) * 60);
        }

        if ($this->trackTokens && isset($response->usage)) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events()->dispatch(TokensUsed::fromLlmCall(
                node: $this->getName(),
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $response->usage->promptTokens ?? 0,
                completionTokens: $response->usage->completionTokens ?? 0,
                callType: 'text',
                model: "{$provider}:{$model}",
                prompt: $promptText,
                response: $responseText
            ));
        }

        return $responseText;
    }

    protected function cache(): CacheRepository
    {
        if ($this->cache === null) {
            $this->cache = app(CacheRepository::class);
        }

        return $this->cache;
    }

    protected function events(): Dispatcher
    {
        if ($this->events === null) {
            $this->events = app(Dispatcher::class);
        }

        return $this->events;
    }

    protected function prismClient(): PrismClient
    {
        if ($this->prismClient !== null) {
            return $this->prismClient;
        }

        return app(PrismClient::class);
    }

    /**
     * Determine if execution should pause before this node runs.
     * Override this method to implement interrupt logic.
     */
    public function shouldInterrupt(\Shahzad\AgenticGraph\Contracts\StateContract $state): bool
    {
        return false; // Default: no interrupt
    }

    /**
     * Provide a human-readable reason for the interrupt.
     * This will be shown to the user.
     */
    public function getInterruptReason(\Shahzad\AgenticGraph\Contracts\StateContract $state): string
    {
        return "Node '{$this->getName()}' requires human review";
    }

    /**
     * Process human input when resuming from interrupt.
     * Override this to handle approval, validation, etc.
     */
    public function onResume(\Shahzad\AgenticGraph\Contracts\StateContract $state, mixed $humanInput): \Shahzad\AgenticGraph\Contracts\StateContract
    {
        // Default: merge array input into state
        if (is_array($humanInput)) {
            foreach ($humanInput as $key => $value) {
                $state = $state->set($key, $value);
            }
        }

        return $state;
    }

    /**
     * Helper: Handle approval workflows.
     */
    protected function handleApproval(
        \Shahzad\AgenticGraph\Contracts\StateContract $state,
        bool $approved,
        ?string $comments = null
    ): \Shahzad\AgenticGraph\Contracts\StateContract {
        return $state
            ->set('approved', $approved)
            ->set('approval_comments', $comments);
    }
}
