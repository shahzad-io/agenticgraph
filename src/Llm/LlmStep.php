<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Llm;

use JsonSerializable;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Tools\ToolRunResult;

/**
 * One model call of a tool or MCP run: its text, the tools it called, their results, its usage and finish reason.
 */
final class LlmStep implements JsonSerializable
{
    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  list<ToolRunResult>  $toolResults
     */
    public function __construct(
        public readonly string $text = '',
        public readonly array $toolCalls = [],
        public readonly array $toolResults = [],
        public readonly ?Usage $usage = null,
        public readonly ?FinishReason $finishReason = null,
    ) {}

    /**
     * @return array{text: string, tool_calls: list<array<string, mixed>>, tool_results: list<array<string, mixed>>, usage: array{prompt_tokens: int, completion_tokens: int, thought_tokens: int|null, cache_read_tokens: int|null, cache_write_tokens: int|null}|null, finish_reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'tool_calls' => array_map(fn (ToolCall $call): array => $call->toArray(), $this->toolCalls),
            'tool_results' => array_map(fn (ToolRunResult $result): array => $result->toArray(), $this->toolResults),
            'usage' => LlmResponse::usageArray($this->usage),
            'finish_reason' => $this->finishReason?->value,
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
