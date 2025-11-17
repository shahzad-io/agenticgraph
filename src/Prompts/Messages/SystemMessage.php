<?php

namespace Shahzad\AgenticGraph\Prompts\Messages;

class SystemMessage extends Message
{
    /**
     * Get the role of this message
     *
     * @return string
     */
    public function getRole(): string
    {
        return 'system';
    }

    /**
     * Create a new system message
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
