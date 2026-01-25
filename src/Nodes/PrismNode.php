<?php

namespace Shahzad\AgenticGraph\Nodes;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Audio\PendingRequest as PendingAudioRequest;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\TextResponse as AudioTextResponse;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Images\PendingRequest as PendingImageRequest;
use Prism\Prism\Images\Response as ImageResponse;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Media\Audio as AudioMedia;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\PromptTemplate;

abstract class PrismNode extends AbstractNode
{
    protected ?string $model = null;

    protected ?int $cacheTtl = null;

    protected bool $trackTokens = true;

    protected function prism(): PendingTextRequest
    {
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);

        return Prism::text()->using($provider, $model);
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

        return Prism::embeddings()->using($provider, $model, $providerConfig);
    }

    protected function image(): PendingImageRequest
    {
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);

        return Prism::image()->using($provider, $model);
    }

    protected function audio(): PendingAudioRequest
    {
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);

        return Prism::audio()->using($provider, $model);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    protected function prompt(string $template, array $variables = [], array $additionalContent = []): string
    {
        $startTime = microtime(true);
        $prompt = $this->interpolate($template, $variables);
        $useCache = $this->shouldCache() && $additionalContent === [];
        $cacheKey = $useCache ? $this->getCacheKey($prompt) : null;
        if ($useCache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);
        $response = $this->prism()->withPrompt($prompt, $additionalContent)->asText();
        $text = $response->text;
        if ($useCache) {
            Cache::put($cacheKey, $text, ($this->cacheTtl ?? (int) config('agenticgraph.prism.cache_ttl')) * 60);
        }
        if ($this->trackTokens && isset($response->usage)) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            Event::dispatch(new TokensUsed(
                node: $this->getName(),
                promptTokens: $response->usage->promptTokens ?? 0,
                completionTokens: $response->usage->completionTokens ?? 0,
                totalTokens: ($response->usage->promptTokens ?? 0) + ($response->usage->completionTokens ?? 0),
                callType: 'text',
                model: "{$provider}:{$model}",
                durationMs: $durationMs
            ));
        }

        return $text;
    }

    /**
     * @param  array<int, Message>  $messages
     */
    protected function promptMessages(array $messages): string
    {
        $startTime = microtime(true);
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);

        $response = $this->prism()
            ->withMessages($messages)
            ->asText();

        $text = $response->text;

        if ($this->trackTokens && isset($response->usage)) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            Event::dispatch(new TokensUsed(
                node: $this->getName(),
                promptTokens: $response->usage->promptTokens ?? 0,
                completionTokens: $response->usage->completionTokens ?? 0,
                totalTokens: ($response->usage->promptTokens ?? 0) + ($response->usage->completionTokens ?? 0),
                callType: 'text',
                model: "{$provider}:{$model}",
                durationMs: $durationMs
            ));
        }

        return $text;
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
            Event::dispatch(new TokensUsed(
                node: $this->getName(),
                promptTokens: $tokens,
                completionTokens: 0,
                totalTokens: $tokens,
                callType: 'embeddings',
                model: "{$provider}:{$model}",
                durationMs: $durationMs
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
            Event::dispatch(new TokensUsed(
                node: $this->getName(),
                promptTokens: $response->usage->promptTokens ?? 0,
                completionTokens: $response->usage->completionTokens ?? 0,
                totalTokens: ($response->usage->promptTokens ?? 0) + ($response->usage->completionTokens ?? 0),
                callType: 'image',
                model: "{$provider}:{$model}",
                durationMs: $durationMs
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
            Event::dispatch(new TokensUsed(
                node: $this->getName(),
                promptTokens: $response->usage->promptTokens ?? 0,
                completionTokens: $response->usage->completionTokens ?? 0,
                totalTokens: ($response->usage->promptTokens ?? 0) + ($response->usage->completionTokens ?? 0),
                callType: 'audio',
                model: "{$provider}:{$model}",
                durationMs: $durationMs
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

        $response = Prism::structured()
            ->using($provider, $model)
            ->withSchema($schema)
            ->withPrompt($prompt)
            ->asStructured();

        if ($this->trackTokens && isset($response->usage)) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            Event::dispatch(new TokensUsed(
                node: $this->getName(),
                promptTokens: $response->usage->promptTokens ?? 0,
                completionTokens: $response->usage->completionTokens ?? 0,
                totalTokens: ($response->usage->promptTokens ?? 0) + ($response->usage->completionTokens ?? 0),
                callType: 'structured',
                model: "{$provider}:{$model}",
                durationMs: $durationMs
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

        $response = $this->prism()
            ->withTools($tools)
            ->withPrompt($prompt)
            ->asText();

        if ($this->trackTokens && isset($response->usage)) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            Event::dispatch(new TokensUsed(
                node: $this->getName(),
                promptTokens: $response->usage->promptTokens ?? 0,
                completionTokens: $response->usage->completionTokens ?? 0,
                totalTokens: ($response->usage->promptTokens ?? 0) + ($response->usage->completionTokens ?? 0),
                callType: 'tools',
                model: "{$provider}:{$model}",
                durationMs: $durationMs
            ));
        }

        return $response->toolCalls;
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

        return 'agenticgraph:prism:'.md5($model.'|'.$prompt);
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

    protected function parseModel(string $value): array
    {
        $parts = explode(':', $value, 2);
        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        return ['openai', $value];
    }

    /**
     * Use a prompt template
     *
     * @param  array<string, mixed>  $variables
     */
    protected function promptTemplate(PromptTemplate $template, array $variables = [], array $additionalContent = []): string
    {
        $startTime = microtime(true);
        $prompt = $template->format($variables);
        $useCache = $this->shouldCache() && $additionalContent === [];
        $cacheKey = $useCache ? $this->getCacheKey($prompt) : null;

        if ($useCache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);
        $response = $this->prism()->withPrompt($prompt, $additionalContent)->asText();
        $text = $response->text;

        if ($useCache) {
            Cache::put($cacheKey, $text, ($this->cacheTtl ?? (int) config('agenticgraph.prism.cache_ttl')) * 60);
        }

        if ($this->trackTokens && isset($response->usage)) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            Event::dispatch(new TokensUsed(
                node: $this->getName(),
                promptTokens: $response->usage->promptTokens ?? 0,
                completionTokens: $response->usage->completionTokens ?? 0,
                totalTokens: ($response->usage->promptTokens ?? 0) + ($response->usage->completionTokens ?? 0),
                callType: 'text',
                model: "{$provider}:{$model}",
                durationMs: $durationMs
            ));
        }

        return $text;
    }

    /**
     * Use a chat prompt template
     *
     * @param  array<string, mixed>  $variables
     */
    protected function chatPrompt(ChatPromptTemplate $template, array $variables = [], array $additionalContent = []): string
    {
        $startTime = microtime(true);
        $prompt = $template->formatAsString($variables);

        $useCache = $this->shouldCache() && $additionalContent === [];
        $cacheKey = $useCache ? $this->getCacheKey($prompt) : null;

        if ($useCache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);
        $response = $this->prism()->withPrompt($prompt, $additionalContent)->asText();
        $text = $response->text;

        if ($useCache) {
            Cache::put($cacheKey, $text, ($this->cacheTtl ?? (int) config('agenticgraph.prism.cache_ttl')) * 60);
        }

        if ($this->trackTokens && isset($response->usage)) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            Event::dispatch(new TokensUsed(
                node: $this->getName(),
                promptTokens: $response->usage->promptTokens ?? 0,
                completionTokens: $response->usage->completionTokens ?? 0,
                totalTokens: ($response->usage->promptTokens ?? 0) + ($response->usage->completionTokens ?? 0),
                callType: 'text',
                model: "{$provider}:{$model}",
                durationMs: $durationMs
            ));
        }

        return $text;
    }
}
