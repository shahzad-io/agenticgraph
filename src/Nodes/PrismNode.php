<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Nodes;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
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
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Media\Audio as AudioMedia;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Relay\Facades\Relay;
use Shahzad\AgenticGraph\Contracts\PrismClient;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Events\ToolCallCaptured;
use Shahzad\AgenticGraph\Exceptions\MissingModelException;
use Shahzad\AgenticGraph\Facades\Trace;
use Shahzad\AgenticGraph\Mcp\McpRequest;
use Shahzad\AgenticGraph\Prompts\ChatPromptMessages;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\PromptTemplate;
use Shahzad\AgenticGraph\Prompts\TemplateInterpolator;
use Shahzad\AgenticGraph\Streaming\PrismStreamAdapter;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Tracing\CaptureDepth;
use Shahzad\AgenticGraph\Tracing\TraceContext;

/**
 * Abstract node with integrated PrismPHP AI capabilities.
 *
 * Helpers for subclasses (protected, call them from execute()): prism(), prompt(), promptTemplate(),
 * chatPrompt(), promptMessages(), structured(), tools(), agent(), withMcp(), embeddings(),
 * embeddingsFromInput(), image(), generateImage(), audio(), audioToText(), textToAudio(),
 * withModel(), withCache(), withoutCache(), handleApproval(). Set $maxTokens or $model to configure the node.
 */
abstract class PrismNode extends AbstractNode
{
    private ?CacheRepository $cache = null;

    private ?Dispatcher $events = null;

    protected ?PrismClient $prismClient = null;

    protected ?string $model = null;

    /** Max output tokens for this node's LLM calls; null falls back to agenticgraph.prism.max_tokens. */
    protected ?int $maxTokens = null;

    /**
     * Prism provider options sent with every text, structured and streamed call (e.g. ['use_tool_calling' => true] for Anthropic structured).
     *
     * @var array<string, mixed>
     */
    protected array $providerOptions = [];

    protected ?int $cacheTtl = null;

    protected bool $trackTokens = true;

    private bool $toolsTracingWrapped = false;

    /**
     * Raw Prism text request factory (timeouts/client options only).
     * For traced multi-step tool agents, use {@see agent()} instead.
     */
    protected function prism(): PendingTextRequest
    {
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);
        $timeout = (int) config('agenticgraph.api.timeout', 30);
        $connectTimeout = (int) config('agenticgraph.api.connect_timeout', 10);

        return $this->withNodeRequestOptions($this->prismClient()->text())
            ->using($provider, $model)
            ->withClientOptions([
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
            ]);
    }

    /**
     * Applies $providerOptions and the output token limit: $maxTokens, then agenticgraph.prism.max_tokens, else none sent.
     *
     * @template T of PendingTextRequest|PendingStructuredRequest
     *
     * @param  T  $request
     * @return T
     */
    private function withNodeRequestOptions(PendingTextRequest|PendingStructuredRequest $request): PendingTextRequest|PendingStructuredRequest
    {
        $configured = config('agenticgraph.prism.max_tokens', null);
        $limit = $this->maxTokens ?? (is_numeric($configured) ? (int) $configured : null);

        if ($limit !== null) {
            $request->withMaxTokens($limit);
        }

        if ($this->providerOptions !== []) {
            $request->withProviderOptions($this->providerOptions);
        }

        return $request;
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
            $request->withTools($this->wrapToolsForTracing($tools, 'prism'));
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
     * @param  array<int, Media|Text>  $additionalContent
     */
    protected function prompt(string $template, array $variables = [], array $additionalContent = []): string
    {
        $startTime = microtime(true);
        $promptText = $this->interpolate($template, $variables);
        if ($this->streamingEnabled()) {
            $request = $this->prism()->withPrompt($promptText, $additionalContent);

            return $this->streamTextRequest($request, $startTime, $promptText);
        }
        $useCache = $this->shouldCache() && $additionalContent === [];
        $cacheKey = $useCache ? $this->getCacheKey($promptText) : null;
        if ($useCache && $this->cache()->has($cacheKey)) {
            $responseText = (string) $this->cache()->get($cacheKey);
            $this->dispatchCachedLlmCall($startTime, $promptText, $responseText, $cacheKey);

            return $responseText;
        }
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);
        $response = $this->prism()->withPrompt($promptText, $additionalContent)->asText();
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

            return $this->streamTextRequest($request, $startTime, $this->extractPromptFromMessages($messages));
        }

        $response = $this->prism()
            ->withMessages($messages)
            ->asText();

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
     * @param  array<int, Media|Text>  $additionalContent
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

        if ($this->trackTokens) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events()->dispatch(TokensUsed::fromLlmCall(
                node: $this->getName(),
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $response->usage->promptTokens,
                completionTokens: $response->usage->completionTokens,
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

        if ($this->trackTokens) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events()->dispatch(TokensUsed::fromLlmCall(
                node: $this->getName(),
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $response->usage->promptTokens,
                completionTokens: $response->usage->completionTokens,
                callType: 'audio',
                model: "{$provider}:{$model}",
                prompt: null,  // Audio input, no text prompt
                response: $response->text
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
                $response = $this->withNodeRequestOptions($this->prismClient()->structured())
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

        $response = $this->withNodeRequestOptions($this->prismClient()->structured())
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
     * @param  array<int, Tool>  $tools
     * @return array<int, ToolCall>
     */
    protected function tools(string $prompt, array $tools): array
    {
        $tools = $this->wrapToolsForTracing($tools, 'prism');
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
                $this->dispatchToolCalls($response->toolCalls, 'prism');

                return $response->toolCalls;
            }
            $traceContext = $this->resolveTraceContext();
            $adapter = new PrismStreamAdapter(
                session: $context->session(),
                nodeName: $this->getName(),
                spanId: $traceContext?->getSpanId(),
                parentSpanId: $traceContext?->getParentSpanId()
            );
            $toolCalls = [];
            $text = '';
            $streamUsage = null;
            foreach ($request->asStream() as $event) {
                if ($event instanceof StreamEndEvent && $event->usage !== null) {
                    $streamUsage = $event->usage;
                }
                $streamEvent = $adapter->adapt($event);
                if ($streamEvent === null) {
                    continue;
                }
                $sink->emit($streamEvent);
                if ($event instanceof ToolCallEvent) {
                    $toolCalls[] = $event->toolCall;
                }
                if ($streamEvent->type() === 'text_delta' && is_string($delta = $streamEvent->payload()['delta'] ?? null)) {
                    $text .= $delta;
                }
            }
            $this->dispatchToolCalls($toolCalls, 'prism');

            if ($this->trackTokens && $streamUsage !== null) {
                $this->events()->dispatch(TokensUsed::fromLlmCall(
                    node: $this->getName(),
                    callStartMicrotime: $startTime,
                    callDurationMs: (microtime(true) - $startTime) * 1000,
                    promptTokens: (int) $streamUsage->promptTokens,
                    completionTokens: (int) $streamUsage->completionTokens,
                    callType: 'tools',
                    model: "{$provider}:{$model}",
                    prompt: $prompt,
                    response: $text,
                    streamed: true,
                ));
            }

            return $toolCalls;
        }

        $response = $this->prism()
            ->withTools($tools)
            ->withPrompt($prompt)
            ->asText();

        $this->dispatchToolCalls($response->toolCalls, 'prism');

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
     * Traced multi-step agent with local Prism tools (wrap + toolChoice + maxSteps + TokensUsed).
     *
     * @param  array<int, Tool>  $tools
     * @param  array{
     *     systemPrompt?: string,
     *     toolChoice?: ToolChoice|string,
     *     maxSteps?: int,
     * }  $options
     */
    protected function agent(string $prompt, array $tools, array $options = []): TextResponse
    {
        $tools = $this->wrapToolsForTracing($tools, 'prism');
        $startTime = microtime(true);
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);

        $request = $this->prism()->withTools($tools);

        $systemPrompt = $options['systemPrompt'] ?? null;
        if (is_string($systemPrompt) && $systemPrompt !== '') {
            $request->withSystemPrompt($systemPrompt);
        }

        $toolChoice = $options['toolChoice'] ?? ToolChoice::Any;
        $request->withToolChoice($toolChoice);

        $maxSteps = is_int($options['maxSteps'] ?? null) ? $options['maxSteps'] : 5;
        $request->withMaxSteps($maxSteps);

        $response = $request->withPrompt($prompt)->asText();

        $this->dispatchToolCalls($response->toolCalls, 'prism');

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

        return $response;
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

        $tools = $this->wrapToolsForTracing($tools, 'mcp');
        $captureToolCalls = ! $this->toolsTracingWrapped;
        // Raw Prism request; McpRequest handles TokensUsed dispatch after asText().
        $request = $this->withNodeRequestOptions($this->prismClient()->text())->using($provider, $model)->withTools($tools);

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
        return TemplateInterpolator::interpolate($template, $variables);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function parseModel(string $value): array
    {
        if ($value === '') {
            throw MissingModelException::notConfigured();
        }

        $parts = explode(':', $value, 2);
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            return [$parts[0], $parts[1]];
        }

        throw MissingModelException::missingProvider($value);
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

        if ($steps instanceof Collection) {
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
     * @param  array<int, Message>|Collection<int, Message>|null  $messages
     */
    private function extractPromptFromMessages(array|Collection|null $messages): ?string
    {
        if ($messages === null) {
            return null;
        }

        // Convert Collection to array if needed
        if ($messages instanceof Collection) {
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
        } catch (BindingResolutionException $e) {
            return null;
        }
    }

    private function streamingEnabled(): bool
    {
        try {
            $context = app(StreamContext::class);
        } catch (BindingResolutionException $e) {
            return false;
        }

        return $context->sink() !== null;
    }

    private function streamTextRequest(
        PendingTextRequest $request,
        ?float $startTime = null,
        ?string $promptForTrace = null,
        string $callType = 'text',
    ): string {
        try {
            $context = app(StreamContext::class);
        } catch (BindingResolutionException $e) {
            return $request->asText()->text;
        }

        $sink = $context->sink();
        if ($sink === null) {
            return $request->asText()->text;
        }

        $callStart = $startTime ?? microtime(true);
        $traceContext = $this->resolveTraceContext();
        $adapter = new PrismStreamAdapter(
            session: $context->session(),
            nodeName: $this->getName(),
            spanId: $traceContext?->getSpanId(),
            parentSpanId: $traceContext?->getParentSpanId()
        );
        $text = '';
        $streamUsage = null;

        foreach ($request->asStream() as $event) {
            if ($event instanceof StreamEndEvent && $event->usage !== null) {
                $streamUsage = [
                    'promptTokens' => (int) $event->usage->promptTokens,
                    'completionTokens' => (int) $event->usage->completionTokens,
                ];
            }

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

        if ($this->trackTokens && $streamUsage !== null) {
            $config = $this->model ?? (string) config('agenticgraph.prism.model');
            [$provider, $model] = $this->parseModel($config);
            $durationMs = (microtime(true) - $callStart) * 1000;
            $this->events()->dispatch(TokensUsed::fromLlmCall(
                node: $this->getName(),
                callStartMicrotime: $callStart,
                callDurationMs: $durationMs,
                promptTokens: $streamUsage['promptTokens'],
                completionTokens: $streamUsage['completionTokens'],
                callType: $callType,
                model: "{$provider}:{$model}",
                prompt: $promptForTrace,
                response: $text,
                streamed: true,
            ));
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
        if ($toolCalls === [] || $this->toolsTracingWrapped) {
            return;
        }

        $traceContext = TraceContext::tryGet();
        if ($traceContext === null) {
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
     * Wrap each Prism tool closure with Trace::span so tool-internal HTTP/DB nests
     * under the tool span. Applied to tools(), withMcp(), and streaming tool paths.
     *
     * Raw Prism tool usage (bypassing these paths) is not wrapped; use agenticgraph's
     * tools()/withMcp() to get tool-scoped tracing.
     *
     * @param  array<int, mixed>  $tools
     * @return array<int, Tool>
     */
    protected function wrapToolsForTracing(array $tools, string $toolType): array
    {
        $wrapped = [];
        $wrappedTools = false;

        foreach ($tools as $tool) {
            if (! $tool instanceof Tool) {
                $wrapped[] = $tool;

                continue;
            }

            $wrappedTool = $this->cloneToolDefinition($tool);
            $toolName = $tool->name();

            $wrappedTool->using(function (...$args) use ($tool, $toolName, $toolType): string {
                return $this->executeToolWithTracing($tool, $toolName, $args, $toolType);
            });

            $wrapped[] = $wrappedTool;
            $wrappedTools = true;
        }

        if ($wrappedTools) {
            $this->toolsTracingWrapped = true;
        }

        return $wrapped;
    }

    private function cloneToolDefinition(Tool $tool): Tool
    {
        $wrappedTool = new Tool;
        $wrappedTool->as($tool->name());
        $wrappedTool->for($tool->description());

        foreach ($tool->parameters() as $name => $schema) {
            $required = in_array($name, $tool->requiredParameters(), true);
            $wrappedTool->withParameter($schema, $required);
        }

        if ($tool->isConcurrent()) {
            $wrappedTool->concurrent(true);
        }

        return $wrappedTool;
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function executeToolWithTracing(Tool $tool, string $toolName, array $args, string $toolType): string
    {
        $startedAt = new \DateTimeImmutable;
        $start = microtime(true);
        $inputSize = $this->payloadSize($args);
        $toolSpanId = null;

        $attributes = array_merge([
            'tool.type' => $toolType,
            'tool.provider' => $this->toolProviderFromName($toolName),
            'tool.input_size' => $inputSize,
        ], CaptureDepth::toolArgsForSpan($args));

        try {
            $result = Trace::span($toolName, function () use ($tool, $args, &$toolSpanId): string {
                $toolSpanId = TraceContext::tryGet()?->getSpanId();

                return $tool->handle(...$args);
            }, $attributes, 'tool');
        } catch (\Throwable $e) {
            $durationMs = (microtime(true) - $start) * 1000;
            $this->dispatchWrappedToolCallEvent(
                toolName: $toolName,
                toolType: $toolType,
                args: $args,
                result: null,
                durationMs: $durationMs,
                startedAt: $startedAt,
                status: 'failed',
                errorType: get_class($e),
                errorMessage: $e->getMessage(),
                toolSpanId: $toolSpanId,
            );
            throw $e;
        }

        $durationMs = (microtime(true) - $start) * 1000;
        [$status, $errorType, $errorMessage] = $this->detectToolError($result);
        $this->dispatchWrappedToolCallEvent(
            toolName: $toolName,
            toolType: $toolType,
            args: $args,
            result: $result,
            durationMs: $durationMs,
            startedAt: $startedAt,
            status: $status,
            errorType: $errorType,
            errorMessage: $errorMessage,
            toolSpanId: $toolSpanId,
        );

        return $result;
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function dispatchWrappedToolCallEvent(
        string $toolName,
        string $toolType,
        array $args,
        ?string $result,
        float $durationMs,
        \DateTimeImmutable $startedAt,
        string $status,
        ?string $errorType,
        ?string $errorMessage,
        ?string $toolSpanId,
    ): void {
        $traceContext = TraceContext::tryGet();
        if ($traceContext === null) {
            return;
        }

        $this->events()->dispatch(new ToolCallCaptured(
            executionId: $traceContext->getExecutionId(),
            spanId: $traceContext->getSpanId(),
            toolName: $toolName,
            toolCallId: null,
            toolType: $toolType,
            toolProvider: $this->toolProviderFromName($toolName),
            inputSize: $this->payloadSize($args),
            outputSize: $this->payloadSize($result),
            durationMs: $durationMs,
            startedAt: $startedAt,
            toolRequestId: null,
            status: $status,
            errorType: $errorType,
            errorMessage: $errorMessage,
            toolSpanId: $toolSpanId,
            spanAlreadyExported: true,
        ));
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
     * @param  array<int, Media|Text>  $additionalContent
     */
    protected function promptTemplate(PromptTemplate $template, array $variables = [], array $additionalContent = []): string
    {
        $startTime = microtime(true);
        $promptText = $template->format($variables);
        if ($this->streamingEnabled()) {
            $request = $this->prism()->withPrompt($promptText, $additionalContent);

            return $this->streamTextRequest($request, $startTime, $promptText);
        }
        $useCache = $this->shouldCache() && $additionalContent === [];
        $cacheKey = $useCache ? $this->getCacheKey($promptText) : null;

        if ($useCache && $this->cache()->has($cacheKey)) {
            $responseText = (string) $this->cache()->get($cacheKey);
            $this->dispatchCachedLlmCall($startTime, $promptText, $responseText, $cacheKey);

            return $responseText;
        }

        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);

        $response = $this->prism()->withPrompt($promptText, $additionalContent)->asText();

        $responseText = $response->text;

        if ($useCache) {
            $this->cache()->put($cacheKey, $responseText, ($this->cacheTtl ?? (int) config('agenticgraph.prism.cache_ttl')) * 60);
        }

        if ($this->trackTokens) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events()->dispatch(TokensUsed::fromLlmCall(
                node: $this->getName(),
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $response->usage->promptTokens,
                completionTokens: $response->usage->completionTokens,
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
     * @param  array<int, Media|Text>  $additionalContent
     */
    protected function chatPrompt(ChatPromptTemplate $template, array $variables = [], array $additionalContent = []): string
    {
        $startTime = microtime(true);
        $formatted = $template->format($variables);
        $messages = ChatPromptMessages::toPrismMessages($formatted, $additionalContent);
        $promptText = $template->formatAsString($variables);

        if ($this->streamingEnabled()) {
            $request = $this->prism()->withMessages($messages);

            return $this->streamTextRequest($request, $startTime, $promptText);
        }

        $useCache = $this->shouldCache() && $additionalContent === [];
        $cacheKey = $useCache ? $this->getCacheKey($promptText) : null;

        if ($useCache && $this->cache()->has($cacheKey)) {
            $responseText = (string) $this->cache()->get($cacheKey);
            $this->dispatchCachedLlmCall($startTime, $promptText, $responseText, $cacheKey);

            return $responseText;
        }

        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);
        $response = $this->prism()->withMessages($messages)->asText();
        $responseText = $response->text;

        if ($useCache) {
            $this->cache()->put($cacheKey, $responseText, ($this->cacheTtl ?? (int) config('agenticgraph.prism.cache_ttl')) * 60);
        }

        if ($this->trackTokens) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events()->dispatch(TokensUsed::fromLlmCall(
                node: $this->getName(),
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $response->usage->promptTokens,
                completionTokens: $response->usage->completionTokens,
                callType: 'text',
                model: "{$provider}:{$model}",
                prompt: $this->extractPromptFromMessages($messages),
                response: $responseText
            ));
        }

        return $responseText;
    }

    protected function dispatchCachedLlmCall(
        float $startTime,
        string $promptText,
        string $responseText,
        ?string $cacheKey = null,
    ): void {
        if (! $this->trackTokens) {
            return;
        }

        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);
        $durationMs = (microtime(true) - $startTime) * 1000;

        $this->events()->dispatch(TokensUsed::fromCacheHit(
            node: $this->getName(),
            callStartMicrotime: $startTime,
            callDurationMs: $durationMs,
            model: "{$provider}:{$model}",
            prompt: $promptText,
            response: $responseText,
            cacheKey: $cacheKey,
        ));
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
    public function shouldInterrupt(StateContract $state): bool
    {
        return false; // Default: no interrupt
    }

    /**
     * Provide a human-readable reason for the interrupt.
     * This will be shown to the user.
     */
    public function getInterruptReason(StateContract $state): string
    {
        return "Node '{$this->getName()}' requires human review";
    }

    public function getInterruptPayload(StateContract $state): mixed
    {
        return null;
    }

    /**
     * Process human input when resuming from interrupt.
     * Override this to handle approval, validation, etc.
     */
    public function onResume(StateContract $state, mixed $humanInput): StateContract
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
        StateContract $state,
        bool $approved,
        ?string $comments = null
    ): StateContract {
        return $state
            ->set('approved', $approved)
            ->set('approval_comments', $comments);
    }
}
