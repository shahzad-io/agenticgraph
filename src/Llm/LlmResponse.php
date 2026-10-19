<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Llm;

use JsonSerializable;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Tools\ToolRunResult;

/**
 * What one request did: the text (of the final step, streamed or not), the structured data (with schema()), the tool
 * calls and results of every step, each step of a tool or MCP run, usage, finish reason, whether it came from the cache, and the context() sources. Stored in state it serializes through toArray().
 */
final class LlmResponse implements JsonSerializable
{
    /**
     * @param  array<mixed>|null  $structured
     * @param  list<ToolCall>  $toolCalls
     * @param  list<ToolRunResult>  $toolResults
     * @param  list<string>  $warnings  e.g. reasoning 'off' that still reported thought tokens
     * @param  object|null  $data  the structured reply hydrated by as(); not serialized (structured holds the array)
     * @param  int  $attempts  model calls the structured reply took; usage covers all of them, or is null when one is unknown
     * @param  list<LlmStep>  $steps  each model call of a tool or MCP run (empty for text and structured calls); usage is their sum
     * @param  list<array{id: string|int, metadata: array<string, mixed>, score: float|null}>  $sources  the items context() put in the prompt
     */
    public function __construct(
        public readonly string $text = '',
        public readonly ?array $structured = null,
        public readonly array $toolCalls = [],
        public readonly array $toolResults = [],
        public readonly ?Usage $usage = null,
        public readonly ?FinishReason $finishReason = null,
        public readonly bool $cached = false,
        public readonly array $warnings = [],
        public readonly ?object $data = null,
        public readonly int $attempts = 1,
        public readonly array $steps = [],
        private readonly array $sources = [],
    ) {}

    /**
     * The items context() put in the prompt (id, metadata, score), in order; empty without context().
     *
     * @return list<array{id: string|int, metadata: array<string, mixed>, score: float|null}>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * @param  list<array{id: string|int, metadata: array<string, mixed>, score: float|null}>  $sources
     */
    public function withSources(array $sources): self
    {
        return new self($this->text, $this->structured, $this->toolCalls, $this->toolResults, $this->usage, $this->finishReason, $this->cached, $this->warnings, $this->data, $this->attempts, $this->steps, $sources);
    }

    /**
     * @param  list<string>  $warnings
     */
    public function withWarnings(array $warnings): self
    {
        return new self($this->text, $this->structured, $this->toolCalls, $this->toolResults, $this->usage, $this->finishReason, $this->cached, $warnings, $this->data, $this->attempts, $this->steps, $this->sources);
    }

    /**
     * @return array{text: string, structured: array<mixed>|null, tool_calls: list<array<string, mixed>>, tool_results: list<array<string, mixed>>, usage: array{prompt_tokens: int, completion_tokens: int, thought_tokens: int|null, cache_read_tokens: int|null, cache_write_tokens: int|null}|null, finish_reason: string|null, cached: bool, warnings: list<string>, attempts: int, steps: list<array<string, mixed>>, sources: list<array{id: string|int, metadata: array<string, mixed>, score: float|null}>}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'structured' => $this->structured,
            'tool_calls' => array_map(fn (ToolCall $call): array => $call->toArray(), $this->toolCalls),
            'tool_results' => array_map(fn (ToolRunResult $result): array => $result->toArray(), $this->toolResults),
            'usage' => self::usageArray($this->usage),
            'finish_reason' => $this->finishReason?->value,
            'cached' => $this->cached,
            'warnings' => $this->warnings,
            'attempts' => $this->attempts,
            'steps' => array_map(fn (LlmStep $step): array => $step->toArray(), $this->steps),
            'sources' => $this->sources,
        ];
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int, thought_tokens: int|null, cache_read_tokens: int|null, cache_write_tokens: int|null}|null
     */
    public static function usageArray(?Usage $usage): ?array
    {
        return $usage !== null
            ? ['prompt_tokens' => $usage->promptTokens, 'completion_tokens' => $usage->completionTokens, 'thought_tokens' => $usage->thoughtTokens, 'cache_read_tokens' => $usage->cacheReadInputTokens, 'cache_write_tokens' => $usage->cacheWriteInputTokens]
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
