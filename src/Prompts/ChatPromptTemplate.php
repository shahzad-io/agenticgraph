<?php

namespace Shahzad\AgenticGraph\Prompts;

use Shahzad\AgenticGraph\Prompts\Messages\Message;
use Shahzad\AgenticGraph\Prompts\Messages\SystemMessage;
use Shahzad\AgenticGraph\Prompts\Messages\UserMessage;
use Shahzad\AgenticGraph\Prompts\Messages\AssistantMessage;
use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;

class ChatPromptTemplate
{
    /**
     * @var array<int, Message>
     */
    protected array $messages = [];

    /**
     * @param array<int, Message> $messages
     */
    public function __construct(array $messages = [])
    {
        $this->messages = $messages;
    }

    /**
     * Add a system message
     *
     * @param string $content
     * @param array<string, mixed> $metadata
     * @return self
     */
    public function system(string $content, array $metadata = []): self
    {
        $this->messages[] = new SystemMessage($content, $metadata);
        return $this;
    }

    /**
     * Add a user message
     *
     * @param string $content
     * @param array<string, mixed> $metadata
     * @return self
     */
    public function user(string $content, array $metadata = []): self
    {
        $this->messages[] = new UserMessage($content, $metadata);
        return $this;
    }

    /**
     * Add an assistant message (for few-shot examples)
     *
     * @param string $content
     * @param array<string, mixed> $metadata
     * @return self
     */
    public function assistant(string $content, array $metadata = []): self
    {
        $this->messages[] = new AssistantMessage($content, $metadata);
        return $this;
    }

    /**
     * Add a custom message
     *
     * @param Message $message
     * @return self
     */
    public function addMessage(Message $message): self
    {
        $this->messages[] = $message;
        return $this;
    }

    /**
     * Format the template with provided variables
     *
     * @param array<string, mixed> $variables
     * @return array<int, array{role: string, content: string}>
     */
    public function format(array $variables = []): array
    {
        return array_map(function (Message $message) use ($variables) {
            $content = $message->getContent();

            // Interpolate variables
            $content = preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($variables) {
                $key = $matches[1];

                if (!array_key_exists($key, $variables)) {
                    throw new TemplateException("Missing template variable: {$key}");
                }

                return $this->formatValue($variables[$key]);
            }, $content);

            return [
                'role' => $message->getRole(),
                'content' => $content,
            ];
        }, $this->messages);
    }

    /**
     * Format the template as a single string
     *
     * @param array<string, mixed> $variables
     * @return string
     */
    public function formatAsString(array $variables = []): string
    {
        $formatted = $this->format($variables);

        return implode("\n\n", array_map(function ($message) {
            return strtoupper($message['role']) . ': ' . $message['content'];
        }, $formatted));
    }

    /**
     * Format a value for insertion into template
     *
     * @param mixed $value
     * @return string
     */
    protected function formatValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Get all messages
     *
     * @return array<int, Message>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Get all variable names used in all messages
     *
     * @return array<int, string>
     */
    public function getVariables(): array
    {
        $variables = [];

        foreach ($this->messages as $message) {
            preg_match_all('/\{(\w+)\}/', $message->getContent(), $matches);
            $variables = array_merge($variables, $matches[1]);
        }

        return array_unique($variables);
    }

    /**
     * Create a new chat prompt template
     *
     * @return self
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Create from an array of messages
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @return self
     */
    public static function fromArray(array $messages): self
    {
        $template = new self();

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            match ($role) {
                'system' => $template->system($content),
                'user' => $template->user($content),
                'assistant' => $template->assistant($content),
                default => throw new TemplateException("Unknown message role: {$role}"),
            };
        }

        return $template;
    }

    /**
     * Convert to string representation
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->formatAsString();
    }
}
