<?php

namespace Shahzad\AgenticGraph\Nodes;

use Illuminate\Support\Facades\Cache;
use Prism\Prism\Facades\Prism;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Prompts\PromptTemplate;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;

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

    /**
     * @param array<string, mixed> $variables
     */
    protected function prompt(string $template, array $variables = []): string
    {
        $prompt = $this->interpolate($template, $variables);
        $cacheKey = $this->getCacheKey($prompt);
        if ($this->shouldCache() && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        $response = $this->prism()->withPrompt($prompt)->asText();
        $text = $response->text;
        if ($this->shouldCache()) {
            Cache::put($cacheKey, $text, ($this->cacheTtl ?? (int) config('agenticgraph.prism.cache_ttl')) * 60);
        }
        if ($this->trackTokens) {
            Event::dispatch(new TokensUsed($this->getName(), $response->usage->promptTokens, $response->usage->completionTokens, $response->usage->completionTokens + $response->usage->promptTokens));
        }
        return $text;
    }

    protected function structured(string $prompt, mixed $schema): mixed
    {
        $config = $this->model ?? (string) config('agenticgraph.prism.model');
        [$provider, $model] = $this->parseModel($config);
        $response = Prism::structured()
            ->using($provider, $model)
            ->withSchema($schema)
            ->withPrompt($prompt)
            ->asStructured();
        return $response->structured;
    }

    /**
     * @param array<int, \EchoLabs\Prism\Tool> $tools
     * @return array<int, \EchoLabs\Prism\ValueObjects\ToolCall>
     */
    protected function tools(string $prompt, array $tools): mixed
    {
        $response = $this->prism()
            ->withTools($tools)
            ->withPrompt($prompt)
            ->asText();
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
        return 'agenticgraph:prism:' . md5($model . '|' . $prompt);
    }

    /**
     * @param array<string, mixed> $variables
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
     * @param PromptTemplate $template
     * @param array<string, mixed> $variables
     * @return string
     */
    protected function promptTemplate(PromptTemplate $template, array $variables = []): string
    {
        $prompt = $template->format($variables);
        $cacheKey = $this->getCacheKey($prompt);

        if ($this->shouldCache() && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $response = $this->prism()->withPrompt($prompt)->asText();
        $text = $response->text;

        if ($this->shouldCache()) {
            Cache::put($cacheKey, $text, ($this->cacheTtl ?? (int) config('agenticgraph.prism.cache_ttl')) * 60);
        }

        if ($this->trackTokens) {
            Event::dispatch(new TokensUsed($this->getName(), $response->usage->promptTokens, $response->usage->completionTokens, $response->usage->completionTokens + $response->usage->promptTokens));
        }

        return $text;
    }

    /**
     * Use a chat prompt template
     *
     * @param ChatPromptTemplate $template
     * @param array<string, mixed> $variables
     * @return string
     */
    protected function chatPrompt(ChatPromptTemplate $template, array $variables = []): string
    {
        // Format as string for now
        // In future, could pass messages array directly to Prism if supported
        $prompt = $template->formatAsString($variables);

        $cacheKey = $this->getCacheKey($prompt);

        if ($this->shouldCache() && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $response = $this->prism()->withPrompt($prompt)->asText();
        $text = $response->text;

        if ($this->shouldCache()) {
            Cache::put($cacheKey, $text, ($this->cacheTtl ?? (int) config('agenticgraph.prism.cache_ttl')) * 60);
        }

        if ($this->trackTokens) {
            Event::dispatch(new TokensUsed($this->getName(), $response->usage->promptTokens, $response->usage->completionTokens, $response->usage->completionTokens + $response->usage->promptTokens));
        }

        return $text;
    }
}
