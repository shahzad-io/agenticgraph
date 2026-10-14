<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Prompts;

use Prism\Prism\Contracts\Message as PrismMessage;
use Prism\Prism\ValueObjects\Messages\AssistantMessage as PrismAssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage as PrismSystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage as PrismToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage as PrismUserMessage;
use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;
use Shahzad\AgenticGraph\Prompts\Messages\AssistantMessage;
use Shahzad\AgenticGraph\Prompts\Messages\Message;
use Shahzad\AgenticGraph\Prompts\Messages\MessagesPlaceholder;
use Shahzad\AgenticGraph\Prompts\Messages\SystemMessage;
use Shahzad\AgenticGraph\Prompts\Messages\UserMessage;

class ChatPromptTemplate
{
    /**
     * @var array<int, Message>
     */
    protected array $messages = [];

    /**
     * @param  array<int, Message>  $messages
     */
    public function __construct(array $messages = [])
    {
        $this->messages = $messages;
    }

    /**
     * Add a system message
     *
     * @param  array<string, mixed>  $metadata
     */
    public function system(string $content, array $metadata = []): self
    {
        $this->messages[] = new SystemMessage($content, $metadata);

        return $this;
    }

    /**
     * Add a user message
     *
     * @param  array<string, mixed>  $metadata
     */
    public function user(string $content, array $metadata = []): self
    {
        $this->messages[] = new UserMessage($content, $metadata);

        return $this;
    }

    /**
     * Add an assistant message (for few-shot examples)
     *
     * @param  array<string, mixed>  $metadata
     */
    public function assistant(string $content, array $metadata = []): self
    {
        $this->messages[] = new AssistantMessage($content, $metadata);

        return $this;
    }

    /**
     * Insert the messages held in a template variable, in order, as real messages.
     *
     * At format time the variable must be a list of `['role' => 'user'|'assistant'|'system',
     * 'content' => string]` arrays or Prism message objects. Their content is never
     * interpolated, so untrusted text such as chat history belongs here. An empty list inserts nothing.
     */
    public function messagesPlaceholder(string $variable): static
    {
        $this->messages[] = new MessagesPlaceholder($variable);

        return $this;
    }

    /**
     * Add a custom message
     */
    public function addMessage(Message $message): self
    {
        $this->messages[] = $message;

        return $this;
    }

    /**
     * Format the template with provided variables. Placeholder messages carry the original
     * Prism object under `message` when one was given.
     *
     * @param  array<string, mixed>  $variables
     * @return array<int, array{role: string, content: string, message?: PrismMessage}>
     */
    public function format(array $variables = []): array
    {
        $formatted = [];

        foreach ($this->messages as $message) {
            if ($message instanceof MessagesPlaceholder) {
                array_push($formatted, ...self::placeholderMessages($message->getVariable(), $variables));

                continue;
            }

            $formatted[] = [
                'role' => $message->getRole(),
                'content' => TemplateInterpolator::interpolate($message->getContent(), $variables),
            ];
        }

        return $formatted;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return list<array{role: string, content: string, message?: PrismMessage}>
     */
    private static function placeholderMessages(string $variable, array $variables): array
    {
        if (! array_key_exists($variable, $variables)) {
            throw new TemplateException("Missing messages placeholder variable: {$variable}");
        }

        $items = $variables[$variable];
        if (! is_array($items) || ! array_is_list($items)) {
            throw new TemplateException("Messages placeholder '{$variable}' must be a list of messages");
        }

        $messages = [];
        foreach ($items as $index => $item) {
            $messages[] = match (true) {
                $item instanceof PrismUserMessage => ['role' => 'user', 'content' => $item->text(), 'message' => $item],
                $item instanceof PrismAssistantMessage => ['role' => 'assistant', 'content' => $item->content, 'message' => $item],
                $item instanceof PrismSystemMessage => ['role' => 'system', 'content' => $item->content, 'message' => $item],
                $item instanceof PrismToolResultMessage => ['role' => 'tool', 'content' => '', 'message' => $item],
                is_array($item) => self::placeholderArrayMessage($variable, $index, $item),
                default => throw new TemplateException(
                    "Messages placeholder '{$variable}' item {$index} must be a message array or a Prism message, got ".get_debug_type($item)
                ),
            };
        }

        return $messages;
    }

    /**
     * @param  array<mixed>  $item
     * @return array{role: string, content: string}
     */
    private static function placeholderArrayMessage(string $variable, int $index, array $item): array
    {
        $role = $item['role'] ?? null;
        if (! in_array($role, ['user', 'assistant', 'system'], true)) {
            $shown = is_string($role) ? substr($role, 0, 32) : get_debug_type($role);

            throw new TemplateException("Messages placeholder '{$variable}' item {$index} has unknown role '{$shown}'");
        }

        if (! is_string($item['content'] ?? null)) {
            throw new TemplateException("Messages placeholder '{$variable}' item {$index} content must be a string");
        }

        return ['role' => $role, 'content' => $item['content']];
    }

    /**
     * Format the template as a single string
     *
     * @param  array<string, mixed>  $variables
     */
    public function formatAsString(array $variables = []): string
    {
        $formatted = $this->format($variables);

        return implode("\n\n", array_map(function ($message) {
            return strtoupper($message['role']).': '.$message['content'];
        }, $formatted));
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
            if ($message instanceof MessagesPlaceholder) {
                $variables[] = $message->getVariable();

                continue;
            }

            $variables = array_merge(
                $variables,
                TemplateInterpolator::extractVariables($message->getContent())
            );
        }

        return array_values(array_unique($variables));
    }

    /**
     * Create a new chat prompt template
     */
    public static function create(): self
    {
        return new self;
    }

    /**
     * Create from an array of messages
     *
     * Warning: every content string here is template text and is interpolated at format time.
     * Never put untrusted input (user or visitor text, chat history) in it; pass that through
     * messagesPlaceholder() or a variable instead.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public static function fromArray(array $messages): self
    {
        $template = new self;

        foreach ($messages as $message) {
            $role = $message['role'];
            $content = $message['content'];

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
     */
    public function __toString(): string
    {
        return $this->formatAsString();
    }
}
