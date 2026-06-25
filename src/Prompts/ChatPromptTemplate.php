<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Prompts;

use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;
use Shahzad\AgenticGraph\Prompts\Messages\AssistantMessage;
use Shahzad\AgenticGraph\Prompts\Messages\Message;
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
     * Add a custom message
     */
    public function addMessage(Message $message): self
    {
        $this->messages[] = $message;

        return $this;
    }

    /**
     * Format the template with provided variables
     *
     * @param  array<string, mixed>  $variables
     * @return array<int, array{role: string, content: string}>
     */
    public function format(array $variables = []): array
    {
        return array_map(function (Message $message) use ($variables) {
            $content = $message->getContent();

            $content = TemplateInterpolator::interpolate($content, $variables);

            return [
                'role' => $message->getRole(),
                'content' => $content,
            ];
        }, $this->messages);
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
