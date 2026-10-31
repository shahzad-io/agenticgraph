<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Nodes;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\PendingRequest as PendingAudioRequest;
use Prism\Prism\Audio\TextResponse as AudioTextResponse;
use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Images\PendingRequest as PendingImageRequest;
use Prism\Prism\Images\Response as ImageResponse;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Media\Audio as AudioMedia;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Text;
use Shahzad\AgenticGraph\Contracts\PrismClient;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Llm\LlmExecutor;
use Shahzad\AgenticGraph\Llm\LlmRequest;

/**
 * Abstract node with integrated PrismPHP AI capabilities.
 *
 * Call a model from execute() with $this->llm() (see {@see LlmRequest}). Other helpers for subclasses: prism() (raw
 * Prism text request), embeddings(), embeddingsFromInput(), image(), generateImage(), audio(), audioToText(),
 * textToAudio(), handleApproval(). Set $model, $maxTokens, $temperature, $providerOptions or $trackTokens to configure the node.
 */
abstract class PrismNode extends AbstractNode
{
    private ?CacheRepository $cache = null;

    private ?Dispatcher $events = null;

    protected ?PrismClient $prismClient = null;

    protected ?string $model = null;

    /** Max output tokens for this node's LLM calls; null falls back to agenticgraph.prism.max_tokens. */
    protected ?int $maxTokens = null;

    /** Sampling temperature (0 to 2) for this node's LLM calls; null falls back to agenticgraph.prism.temperature. */
    protected ?float $temperature = null;

    /**
     * Prism provider options sent with every request (e.g. ['use_tool_calling' => true] for Anthropic structured).
     * A request's providerOptions() is merged over these, per key.
     *
     * @var array<string, mixed>
     */
    protected array $providerOptions = [];

    /**
     * Reasoning level for this node's LLM calls: off, low, medium or high. Null sends nothing (the provider's default).
     * No config layer: a global level would break nodes on providers that cannot apply it. A request's reasoning() wins.
     */
    protected ?string $reasoning = null;

    /** Reasoning token budget, for providers that take one. Exclusive with $reasoning. */
    protected ?int $reasoningBudget = null;

    /** Cache TTL in minutes for this node's requests; null falls back to agenticgraph.prism.cache_ttl. */
    protected ?int $cacheTtl = null;

    /** Node default for caching; null follows agenticgraph.prism.cache_enabled. A request's withCache() / withoutCache() wins. */
    protected ?bool $cacheEnabled = null;

    protected bool $trackTokens = true;

    /** A new model request. Immutable: every builder method returns a new request. */
    protected function llm(): LlmRequest
    {
        return new LlmRequest($this->llmExecutor());
    }

    /**
     * Raw Prism text request with the node's model, max tokens, provider options and timeouts. Not traced, cached or
     * streamed; prefer {@see llm()}.
     */
    protected function prism(): PendingTextRequest
    {
        return $this->llmExecutor()->textRequest();
    }

    private function llmExecutor(): LlmExecutor
    {
        return new LlmExecutor(
            client: $this->prismClient(),
            cache: $this->cache(),
            events: $this->events(),
            nodeName: $this->getName(),
            cacheScope: static::class,
            model: $this->model,
            maxTokens: $this->maxTokens,
            providerOptions: $this->providerOptions,
            trackTokens: $this->trackTokens,
            cacheEnabled: $this->cacheEnabled,
            cacheTtl: $this->cacheTtl,
            reasoning: $this->reasoning,
            reasoningBudget: $this->reasoningBudget,
            temperature: $this->temperature,
        );
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

        // Duration-billed transcriptions (whisper-1, Groq) report no token usage: nothing to track.
        if ($this->trackTokens && $response->usage !== null) {
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

    /**
     * @return array{0: string, 1: string}
     */
    protected function parseModel(string $value): array
    {
        return LlmExecutor::parseModel($value);
    }

    /**
     * Wraps each Prism tool so its handler runs inside Trace::span (tool-internal HTTP and DB nest under the tool
     * span). {@see llm()} does this for every request with tools; this is for raw Prism use.
     *
     * @param  array<int, mixed>  $tools
     * @return array<int, mixed>
     */
    protected function wrapToolsForTracing(array $tools, string $toolType): array
    {
        return $this->llmExecutor()->wrapTools($tools, $toolType);
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
