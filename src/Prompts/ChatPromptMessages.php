<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Prompts;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;

/**
 * Maps formatted chat prompt messages to Prism message value objects.
 */
final class ChatPromptMessages
{
    /**
     * @param  array<int, array{role: string, content: string, message?: Message}>  $formatted
     * @param  array<int, Media|Text>  $additionalContent
     * @return array<int, Message>
     */
    public static function toPrismMessages(array $formatted, array $additionalContent = []): array
    {
        $lastUserIndex = null;

        foreach ($formatted as $index => $message) {
            if ($message['role'] === 'user') {
                $lastUserIndex = $index;
            }
        }

        $messages = [];

        foreach ($formatted as $index => $message) {
            $content = $message['content'];
            $media = ($index === $lastUserIndex && $additionalContent !== []) ? $additionalContent : [];

            // Placeholder messages given as Prism objects pass through unchanged.
            if (isset($message['message'])) {
                $original = $message['message'];
                $messages[] = $original instanceof UserMessage && $media !== []
                    ? new UserMessage($original->content, [...$original->additionalContent, ...$media], $original->additionalAttributes)
                    : $original;

                continue;
            }

            $messages[] = match ($message['role']) {
                'system' => new SystemMessage($content),
                'user' => new UserMessage($content, $media),
                'assistant' => new AssistantMessage($content),
                default => throw new TemplateException("Unknown message role: {$message['role']}"),
            };
        }

        return $messages;
    }
}
