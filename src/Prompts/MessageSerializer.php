<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Prompts;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * One serializer for a Prism message list, built per message class: role, content, media, tool calls and
 * tool results. PrismNode uses toArray() for the reply cache key and toText() for the prompt in TokensUsed.
 */
final class MessageSerializer
{
    /**
     * @param  iterable<Message>  $messages
     * @return list<array<string, mixed>>
     */
    public static function toArray(iterable $messages): array
    {
        $serialized = [];
        foreach ($messages as $message) {
            $serialized[] = self::message($message);
        }

        return $serialized;
    }

    /**
     * @param  iterable<Message>  $messages
     */
    public static function toText(iterable $messages): ?string
    {
        $parts = [];
        foreach (self::toArray($messages) as $message) {
            $lines = [];
            foreach ($message['tool_calls'] ?? [] as $call) {
                $lines[] = "[tool_call] {$call['name']} ".self::json($call['arguments']);
            }
            foreach ($message['tool_results'] ?? [] as $result) {
                $lines[] = "[tool_result] {$result['tool_name']}: ".(is_string($result['result']) ? $result['result'] : self::json($result['result']));
            }
            $content = (string) ($message['content'] ?? '');
            if ($content !== '' || $lines === []) {
                $media = ($message['media'] ?? []) !== [] ? ' [media: '.implode(', ', $message['media']).']' : '';
                array_unshift($lines, rtrim("[{$message['role']}] {$content}{$media}"));
            }
            $parts[] = implode("\n", $lines);
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * True when a user message carries media (anything but text parts). Such calls are not cached.
     *
     * @param  iterable<Message>  $messages
     */
    public static function hasMedia(iterable $messages): bool
    {
        foreach (self::toArray($messages) as $message) {
            if (($message['media'] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(Message $message): array
    {
        return match (true) {
            $message instanceof SystemMessage => ['role' => 'system', 'content' => $message->content],
            $message instanceof UserMessage => [
                'role' => 'user',
                'content' => $message->text(),
                'media' => array_values(array_map(
                    fn (object $part): string => strtolower(class_basename($part)),
                    array_filter($message->additionalContent, fn (object $part): bool => ! $part instanceof Text),
                )),
            ],
            $message instanceof AssistantMessage => [
                'role' => 'assistant',
                'content' => $message->content,
                'tool_calls' => array_map(fn (ToolCall $call): array => [
                    'id' => $call->id,
                    'name' => $call->name,
                    'arguments' => $call->arguments(),
                ], $message->toolCalls),
            ],
            $message instanceof ToolResultMessage => [
                'role' => 'tool',
                'tool_results' => array_map(fn (ToolResult $result): array => [
                    'tool_call_id' => $result->toolCallId,
                    'tool_name' => $result->toolName,
                    'args' => $result->args,
                    'result' => $result->result,
                ], $message->toolResults),
            ],
            default => [
                'role' => strtolower(class_basename($message)),
                'content' => $message instanceof Arrayable ? self::json($message->toArray()) : '',
            ],
        };
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
