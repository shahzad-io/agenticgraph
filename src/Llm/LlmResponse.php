<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Llm;

use JsonSerializable;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Tools\ToolRunResult;

/**
 * What one request did: the text, the structured data (with schema()), the tool calls and results of every step,
 * usage, finish reason, and whether it came from the cache. Stored in state it serializes through toArray().
 */
final class LlmResponse implements JsonSerializable
{
    /**
     * @param  array<mixed>|null  $structured
     * @param  list<ToolCall>  $toolCalls
     * @param  list<ToolRunResult>  $toolResults
     */
    public function __construct(
        public readonly string $text = '',
        public readonly ?array $structured = null,
        public readonly array $toolCalls = [],
        public readonly array $toolResults = [],
        public readonly ?Usage $usage = null,
        public readonly ?FinishReason $finishReason = null,
        public readonly bool $cached = false,
    ) {}

    /**
     * @return array{text: string, structured: array<mixed>|null, tool_calls: list<array<string, mixed>>, tool_results: list<array<string, mixed>>, usage: array{prompt_tokens: int, completion_tokens: int}|null, finish_reason: string|null, cached: bool}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'structured' => $this->structured,
            'tool_calls' => array_map(fn (ToolCall $call): array => $call->toArray(), $this->toolCalls),
            'tool_results' => array_map(fn (ToolRunResult $result): array => $result->toArray(), $this->toolResults),
            'usage' => $this->usage !== null
                ? ['prompt_tokens' => $this->usage->promptTokens, 'completion_tokens' => $this->usage->completionTokens]
                : null,
            'finish_reason' => $this->finishReason?->value,
            'cached' => $this->cached,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
