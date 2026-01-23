<?php

namespace Shahzad\AgenticGraph\Prompts\Messages;

class UserMessage extends Message
{
    /**
     * Get the role of this message
     */
    public function getRole(): string
    {
        return 'user';
    }

    /**
     * Create a new user message
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function create(string $content, array $metadata = []): self
    {
        return new self($content, $metadata);
    }
}
