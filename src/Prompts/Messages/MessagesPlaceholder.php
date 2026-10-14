<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Prompts\Messages;

/**
 * Marks where a list of messages from a template variable is inserted. Never interpolated.
 */
class MessagesPlaceholder extends Message
{
    public function __construct(private readonly string $variable)
    {
        parent::__construct('');
    }

    public function getRole(): string
    {
        return 'placeholder';
    }

    public function getVariable(): string
    {
        return $this->variable;
    }
}
