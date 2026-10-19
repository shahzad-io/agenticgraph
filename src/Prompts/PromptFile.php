<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Prompts;

use Shahzad\AgenticGraph\Prompts\Messages\Message;

/**
 * A chat template loaded from a prompt file by {@see Prompts::get()}. The llm span carries its name, version and hash.
 */
class PromptFile extends ChatPromptTemplate
{
    /**
     * @param  array<int, Message>  $messages
     * @param  string  $hash  first 12 hex of the sha256 of the raw file
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $hash,
        array $messages = [],
        public readonly ?string $description = null,
    ) {
        parent::__construct($messages);
    }

    /**
     * @return array{name: string, version: string, hash: string}
     */
    public function meta(): array
    {
        return ['name' => $this->name, 'version' => $this->version, 'hash' => $this->hash];
    }
}
