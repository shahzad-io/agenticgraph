<?php

namespace Shahzad\AgenticGraph\Prompts\Messages;

class AssistantMessage extends Message
{
    /**
     * Get the role of this message
     */
    public function getRole(): string
    {
        return 'assistant';
    }

    /**
     * Create a new assistant message
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function create(string $content, array $metadata = []): self
    {
        return new self($content, $metadata);
    }
}
