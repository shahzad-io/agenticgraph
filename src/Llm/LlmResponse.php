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
 * calls and results of every step, each step of a tool or MCP run, usage, finish reason, whether it came from the cache, the context() sources, and the provider and model that answered.
 * Stored in state it serializes through toArray(); toReplay() gives what a later turn sends back with replay().
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
     * @param  string|null  $terminalTool  the terminal tool that ended the run (terminalTools()); null when none did
     * @param  array<string, mixed>  $terminalData  the data of its TerminalResult
     * @param  string|null  $provider  the provider that answered (null on a response not built by a request)
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
        public readonly ?string $terminalTool = null,
        public readonly array $terminalData = [],
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
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
        return $this->with(['sources' => $sources]);
    }

    /**
     * @param  list<string>  $warnings
     */
    public function withWarnings(array $warnings): self
    {
        return $this->with(['warnings' => $warnings]);
    }

    public function withModel(string $provider, string $model): self
    {
        return $this->with(['provider' => $provider, 'model' => $model]);
    }

    /**
     * What a later turn sends back with replay() or a messages placeholder: plain JSON-safe data with this reply's
     * assistant and tool messages (a tool run's steps, or the one reply), the provider content each provider needs to
     * accept them again, and the provider and model. The user message that started the turn is not in it.
     *
     * @return array{version: int, provider: string, model: string, messages: list<array<string, mixed>>}
     */
    public function toReplay(): array
    {
        if ($this->provider === null || $this->model === null) {
            throw new \LogicException('toReplay() needs the provider and model that answered: use the response a request returned.');
        }

        $steps = $this->steps !== [] ? $this->steps : [new LlmStep($this->text)];
        $messages = [];
        foreach ($steps as $step) {
            $messages[] = ['role' => 'assistant', 'content' => $step->text, 'tool_calls' => $step->toArray()['tool_calls'], 'provider_content' => $step->providerContent];
            if ($step->toolResults !== []) {
                $messages[] = ['role' => 'tool', 'tool_results' => array_map(fn (ToolRunResult $result): array => [
                    'tool_call_id' => $result->toolCallId,
                    'tool_name' => $result->toolName,
                    'arguments' => $result->arguments,
                    'result' => $result->result,
                    'call_id' => $result->callId,
                ], $step->toolResults)];
            }
        }

        return ['version' => Replay::VERSION, 'provider' => $this->provider, 'model' => $this->model, 'messages' => $messages];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    /**
     * @return array{text: string, structured: array<mixed>|null, tool_calls: list<array<string, mixed>>, tool_results: list<array<string, mixed>>, usage: array{prompt_tokens: int, completion_tokens: int, thought_tokens: int|null, cache_read_tokens: int|null, cache_write_tokens: int|null}|null, finish_reason: string|null, cached: bool, warnings: list<string>, attempts: int, steps: list<array<string, mixed>>, sources: list<array{id: string|int, metadata: array<string, mixed>, score: float|null}>, terminal_tool: string|null, terminal_data: array<string, mixed>, provider: string|null, model: string|null}
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
            'terminal_tool' => $this->terminalTool,
            'terminal_data' => $this->terminalData,
            'provider' => $this->provider,
            'model' => $this->model,
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
