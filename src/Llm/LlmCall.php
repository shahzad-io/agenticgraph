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
     * @param  string|null  $reasoning  off, low, medium or high; this call's, wins over the node's
     * @param  string|null  $structuredMode  auto, json or structured (OpenAI only); this call's
     * @param  class-string|null  $dto  hydrate the structured reply into this class
     * @param  int|null  $structuredRetries  overrides config agenticgraph.prism.structured.retries (PrismRouter pins 0)
     * @param  bool  $structuredTraceAsJson  TokensUsed response is the JSON of the structured data (PrismRouter parity)
     * @param  array{as: string, text: string, items: list<array{id: string|int, content: string, metadata: array<string, mixed>, score: float|null}>}|null  $context  set by context(): the variable, its text and the items
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
        public ?string $reasoning = null,
        public ?int $reasoningBudget = null,
        public ?string $structuredMode = null,
        public ?string $dto = null,
        public ?int $structuredRetries = null,
        public ?array $context = null,
    ) {}

    /**
     * The items context() used, without their content.
     *
     * @return list<array{id: string|int, metadata: array<string, mixed>, score: float|null}>
     */
    public function sources(): array
    {
        return array_map(fn (array $item): array => ['id' => $item['id'], 'metadata' => $item['metadata'], 'score' => $item['score']], $this->context['items'] ?? []);
    }

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
