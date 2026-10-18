<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Llm;

use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Shahzad\AgenticGraph\Prompts\MessageSerializer;

/**
 * One resolved request for {@see LlmExecutor}: templates are already formatted. Either $prompt is set (sent with
 * withPrompt, $system as the system prompt) or $messages is (system already prepended, media already attached).
 *
 * @internal Built by {@see LlmRequest} and PrismRouter.
 */
final readonly class LlmCall
{
    /**
     * @param  array<int, Media|Text>  $media  attachments for $prompt
     * @param  array<int, Message>  $messages
     * @param  array<int, mixed>  $tools
     * @param  list<array{server: string, options: array<string, mixed>}>  $mcp
     * @param  array<string, mixed>  $providerOptions  merged over the node's, call wins per key
     * @param  bool|null  $stream  false never streams (PrismRouter); null follows the streaming matrix
     * @param  bool  $structuredTraceAsJson  TokensUsed response is the JSON of the structured data (PrismRouter parity)
     */
    public function __construct(
        public ?string $prompt = null,
        public array $media = [],
        public array $messages = [],
        public ?string $system = null,
        public mixed $schema = null,
        public array $tools = [],
        public array $mcp = [],
        public bool $toolRequest = false,
        public string|ToolChoice|null $toolChoice = null,
        public ?int $maxSteps = null,
        public ?string $model = null,
        public ?int $maxTokens = null,
        public array $providerOptions = [],
        public ?bool $cache = null,
        public ?int $cacheTtl = null,
        public ?bool $stream = null,
        public bool $structuredTraceAsJson = false,
    ) {}

    /**
     * The canonical message list: what the cache key covers and the media check reads.
     *
     * @return array<int, Message>
     */
    public function keyMessages(): array
    {
        return $this->prompt !== null ? [new UserMessage($this->prompt, $this->media)] : $this->messages;
    }

    public function promptForTrace(): ?string
    {
        return $this->prompt ?? MessageSerializer::toText($this->messages);
    }

    /**
     * @return list<string>
     */
    public function toolNames(): array
    {
        return array_values(array_map(
            fn (mixed $tool): string => $tool instanceof Tool ? $tool->name() : get_debug_type($tool),
            $this->tools,
        ));
    }
}
