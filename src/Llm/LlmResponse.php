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
     * @param  bool  $heldText  the text of the last step was held (holdStepText) and never sent: the terminal tool's reply replaced it
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
        public readonly bool $heldText = false,
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
     * A run that a terminal tool ended ends with the reply the user got, as an assistant message after the tool
     * message. The held text of its last step (holdStepText), which the user never saw, is left out: the step text,
     * the OpenAI message items, and the Anthropic text blocks that no thinking block follows (a later thinking block
     * is bound to the blocks before it). The reasoning items and signed thinking blocks stay.
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
        foreach ($steps as $i => $step) {
            $held = $this->heldText && $this->terminalTool !== null && $i === array_key_last($steps);
            [$text, $content] = $held ? self::withoutText($step->providerContent) : [$step->text, $step->providerContent];
            $messages[] = ['role' => 'assistant', 'content' => $text, 'tool_calls' => $step->toArray()['tool_calls'], 'provider_content' => $content];
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
        // A terminal tool's reply is set outside the steps: it is what the user got, so the next turn must see it.
        if ($this->terminalTool !== null && $this->text !== '') {
            $messages[] = ['role' => 'assistant', 'content' => $this->text, 'tool_calls' => [], 'provider_content' => []];
        }

        return ['version' => Replay::VERSION, 'provider' => $this->provider, 'model' => $this->model, 'messages' => $messages];
    }

    /**
     * The provider content of a step without its text, and the text that has to stay.
     *
     * @param  array<string, mixed>  $content
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function withoutText(array $content): array
    {
        if (is_array($content['output_items'] ?? null)) {
            $content['output_items'] = array_values(array_filter($content['output_items'], fn (mixed $item): bool => ! is_array($item) || ($item['type'] ?? null) !== 'message'));
        }
        $kept = '';
        if (is_array($content['content_blocks'] ?? null)) {
            $blocks = $content['content_blocks'];
            $lastThinking = -1;
            foreach ($blocks as $i => $block) {
                $lastThinking = in_array($block['type'] ?? null, ['thinking', 'redacted_thinking'], true) ? $i : $lastThinking;
            }
            $blocks = array_filter($blocks, fn (mixed $block, int $i): bool => $i < $lastThinking || ($block['type'] ?? null) !== 'text', ARRAY_FILTER_USE_BOTH);
            $content['content_blocks'] = array_values($blocks);
            $kept = implode('', array_map(fn (array $block): string => $block['type'] === 'text' ? (string) $block['text'] : '', $content['content_blocks']));
        }

        return [$kept, $content];
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
