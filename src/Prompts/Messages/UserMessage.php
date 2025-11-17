<?php

namespace Shahzad\AgenticGraph\Prompts\Messages;

class UserMessage extends Message
{
    /**
     * Get the role of this message
     *
     * @return string
     */
    public function getRole(): string
    {
        return 'user';
    }

    /**
     * Create a new user message
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
