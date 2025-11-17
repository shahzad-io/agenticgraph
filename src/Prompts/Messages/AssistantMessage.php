<?php

namespace Shahzad\AgenticGraph\Prompts\Messages;

class AssistantMessage extends Message
{
    /**
     * Get the role of this message
     *
     * @return string
     */
    public function getRole(): string
    {
        return 'assistant';
    }

    /**
     * Create a new assistant message
     *
     * @param string $content
     * @param array<string, mixed> $metadata
     * @return self
     */
    public static function create(string $content, array $metadata = []): self
    {
        return new self($content, $metadata);
    }
}
