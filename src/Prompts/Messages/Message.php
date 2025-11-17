<?php

namespace Shahzad\AgenticGraph\Prompts\Messages;

abstract class Message
{
    protected string $content;
    protected array $metadata;

    public function __construct(string $content, array $metadata = [])
    {
        $this->content = $content;
        $this->metadata = $metadata;
    }

    /**
     * Get the role of this message
     *
     * @return string
     */
    abstract public function getRole(): string;

    /**
     * Get the message content
     *
     * @return string
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Get message metadata
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Set message metadata
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function withMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Convert message to array format
     *
     * @return array{role: string, content: string, metadata: array}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->getRole(),
            'content' => $this->content,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Convert message to string
     *
     * @return string
     */
    public function __toString(): string
    {
        return strtoupper($this->getRole()) . ': ' . $this->content;
    }
}
