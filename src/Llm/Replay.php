<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Llm;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * The one converter between LlmResponse::toReplay() data and Prism messages, for replay() and messages placeholders.
 *
 * A replayed assistant message keeps the provider content Prism sends back (OpenAI reasoning items, Anthropic signed
 * thinking blocks, OpenRouter reasoning details; Gemini thought signatures are on the tool calls). Another model of
 * the same provider gets all of it: OpenAI rejects a function_call replayed without its reasoning item on any reasoning
 * model, and Anthropic drops the thinking a model cannot read. Another provider is refused: tool call ids are provider-shaped.
 */
final class Replay
{
    public const VERSION = 1;

    /** The Prism step additionalContent keys that providers read back from an assistant message. */
    public const PROVIDER_KEYS = ['output_items', 'content_blocks', 'thinking_blocks', 'thinking', 'thinking_signature', 'reasoning', 'reasoning_details'];

    /** Marks a replayed assistant message with its source; removed by forProvider() before Prism sees it. */
    private const SOURCE = 'agenticgraph_replay_source';

    /**
     * @param  array<mixed>  $data
     */
    public static function is(array $data): bool
    {
        return isset($data['version'], $data['messages']);
    }

    /**
     * @param  array<mixed>  $data  what LlmResponse::toReplay() returned, after a JSON round trip or not
     * @return list<AssistantMessage|ToolResultMessage>
     */
    public static function toMessages(array $data): array
    {
        if (($data['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported replay version ['.json_encode($data['version'] ?? null).']. This version of AgenticGraph reads version '.self::VERSION.'.');
        }
        if (! is_string($data['provider'] ?? null) || ! is_string($data['model'] ?? null) || ! is_array($data['messages']) || ! array_is_list($data['messages'])) {
            throw new \InvalidArgumentException('A replay needs provider, model and a list of messages, as LlmResponse::toReplay() returns them.');
        }

        $source = ['provider' => $data['provider'], 'model' => $data['model']];

        return array_map(fn (mixed $message): AssistantMessage|ToolResultMessage => match (is_array($message) ? $message['role'] ?? null : null) {
            'assistant' => new AssistantMessage(
                (string) ($message['content'] ?? ''),
                array_map(fn (array $call): ToolCall => new ToolCall($call['id'], $call['name'], $call['arguments'], $call['result_id'] ?? null, $call['reasoning_id'] ?? null, $call['reasoning_summary'] ?? null), $message['tool_calls'] ?? []),
                [...($message['provider_content'] ?? []), self::SOURCE => $source],
            ),
            'tool' => new ToolResultMessage(array_map(
                fn (array $result): ToolResult => new ToolResult($result['tool_call_id'], $result['tool_name'], $result['arguments'], $result['result'], $result['call_id'] ?? null),
                $message['tool_results'] ?? [],
            )),
            default => throw new \InvalidArgumentException('A replay message has role assistant or tool.'),
        }, $data['messages']);
    }

    /**
     * Checks each replayed message against the provider of the request and removes the source mark.
     *
     * @param  array<int, Message>  $messages
     * @return array<int, Message>
     */
    public static function forProvider(array $messages, string $provider): array
    {
        return array_map(function (Message $message) use ($provider): Message {
            $source = $message instanceof AssistantMessage ? $message->additionalContent[self::SOURCE] ?? null : null;
            if (! is_array($source)) {
                return $message;
            }
            if ($source['provider'] !== $provider) {
                throw new \InvalidArgumentException("A replay from [{$source['provider']}:{$source['model']}] cannot be sent to the [{$provider}] provider: tool call ids and reasoning are provider-specific. Send the earlier turns as text with history().");
            }
            $content = $message->additionalContent;
            unset($content[self::SOURCE]);

            return new AssistantMessage($message->content, $message->toolCalls, $content);
        }, $messages);
    }
}
