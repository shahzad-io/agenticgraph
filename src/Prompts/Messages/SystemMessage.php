<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Prompts\Messages;

class SystemMessage extends Message
{
    /**
     * Get the role of this message
     */
    public function getRole(): string
    {
        return 'system';
    }

    /**
     * Create a new system message
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function create(string $content, array $metadata = []): self
    {
        return new self($content, $metadata);
    }
}
