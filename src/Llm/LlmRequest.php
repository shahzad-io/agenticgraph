<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Llm;

use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Text;
use Shahzad\AgenticGraph\Prompts\ChatPromptMessages;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\PromptTemplate;

/**
 * One model call, built from a node with $this->llm(). Immutable: every method returns a new request.
 *
 * Templates interpolate (prompt(), chat(), with vars()); inline content (system(), user(), assistant(), history())
 * never does. Settings: config, then the node's properties, then model() / maxTokens() / providerOptions() here. reasoning() has
 * no config layer (node, then call).
 *
 *     $this->llm()->prompt('Summarize {topic}')->vars(['topic' => $topic])->text();
 *     $this->llm()->chat($template)->vars($vars)->user($visitorText)->text();
 *     $this->llm()->prompt($ask)->schema($schema)->structured();
 *     $this->llm()->system($rules)->prompt($ask)->tools($tools)->toolChoice('lookup')->maxSteps(5)->run();
 */
final class LlmRequest
{
    private const PROMPT_WITH_MESSAGES = 'prompt() cannot be combined with user(), assistant() or history() (Prism refuses a prompt and messages together); use chat().';

    private PromptTemplate|ChatPromptTemplate|null $template = null;

    private ?string $system = null;

    /** @var list<array{role: string, content: string, message?: Message}> */
    private array $inline = [];

    /** @var array<string, mixed> */
    private array $vars = [];

    /** @var array<int, Media|Text> */
    private array $media = [];

    /** @var array<int, mixed> */
    private array $tools = [];

    /** @var list<array{server: string, options: array<string, mixed>}> */
    private array $mcp = [];

    private bool $toolRequest = false;

    private string|ToolChoice|null $toolChoice = null;

    private ?int $maxSteps = null;

    private mixed $schema = null;

    /** @var array<string, mixed> */
    private array $providerOptions = [];

    private ?bool $cache = null;

    private ?int $cacheTtl = null;

    private ?string $model = null;

    private ?int $maxTokens = null;

    private ?string $reasoning = null;

    private ?int $reasoningBudget = null;

    private ?string $structuredMode = null;

    /** @var class-string|null */
    private ?string $dto = null;

    /** @var array{as: string, items: list<array{id: string|int, content: string, metadata: array<string, mixed>, score: float|null}>, format: PromptTemplate|null, separator: string}|null */
    private ?array $context = null;

    public function __construct(private readonly LlmExecutor $executor) {}

    /** A string is wrapped in a new PromptTemplate. Interpolated with vars(). */
    public function prompt(string|PromptTemplate $prompt): self
    {
        $this->assertNoTemplate();
        if ($this->inline !== []) {
            throw new \LogicException(self::PROMPT_WITH_MESSAGES);
        }

        return $this->with('template', is_string($prompt) ? new PromptTemplate($prompt) : $prompt);
    }

    /** Interpolated with vars(); placeholders take message lists from vars() and are never interpolated. */
    public function chat(ChatPromptTemplate $template): self
    {
        $this->assertNoTemplate();

        return $this->with('template', $template);
    }

    /** Literal. The system prompt with prompt(), prepended to the messages otherwise. */
    public function system(string $content): self
    {
        return $this->with('system', $content);
    }

    /** Literal, appended after the chat() messages. */
    public function user(string $content): self
    {
        return $this->withInline([['role' => 'user', 'content' => $content]]);
    }

    /** Literal, appended after the chat() messages. */
    public function assistant(string $content): self
    {
        return $this->withInline([['role' => 'assistant', 'content' => $content]]);
    }

    /**
     * Literal, appended in order. Prism messages, or arrays with role (user, assistant, system) and content.
     *
     * @param  array<int, mixed>  $messages
     */
    public function history(array $messages): self
    {
        return $this->withInline(ChatPromptTemplate::create()->messagesPlaceholder('history')->format(['history' => $messages]));
    }

    /**
     * Merged into the variables the templates are formatted with. Templates are never mutated.
     *
     * @param  array<string, mixed>  $vars
     */
    public function vars(array $vars): self
    {
        return $this->with('vars', array_merge($this->vars, $vars));
    }

    /**
     * Retrieved items as one variable. $items is a list of arrays (id, content, optional metadata and score) or any
     * object with toContextItems() returning that list (a vector SearchResult). Each item renders as its content, or
     * through $format with the item's metadata plus id, content and score as variables. The joined text is a value:
     * never interpolated again. run()->sources() returns the items used.
     *
     *     $this->llm()->prompt("Answer from:\n{context}\n\nQ: {q}")->vars(['q' => $q])->context($hits)->text();
     *
     * @param  iterable<mixed>|object  $items
     */
    public function context(iterable|object $items, string $as = 'context', ?PromptTemplate $format = null, string $separator = "\n\n"): self
    {
        if (! is_iterable($items)) {
            if (! method_exists($items, 'toContextItems')) {
                throw new \InvalidArgumentException('context() takes a list of items or an object with toContextItems(), got ['.$items::class.'].');
            }
            $items = $items->toContextItems();
        }

        $list = [];
        foreach ($items as $index => $item) {
            if (! is_array($item) || ! array_key_exists('id', $item) || ! (is_string($item['id']) || is_int($item['id'])) || ! is_string($item['content'] ?? null)) {
                throw new \InvalidArgumentException("context() item [{$index}] needs an id (string or int) and a string content.");
            }
            $list[] = ['id' => $item['id'], 'content' => $item['content'], 'metadata' => (array) ($item['metadata'] ?? []), 'score' => isset($item['score']) ? (float) $item['score'] : null];
        }

        return $this->with('context', ['as' => $as, 'items' => $list, 'format' => $format, 'separator' => $separator]);
    }

    /**
     * Media for the last user turn.
     *
     * @param  array<int, Media|Text>  $media
     */
    public function attach(array $media): self
    {
        return $this->with('media', [...$this->media, ...$media]);
    }

    /**
     * Tools for the model. Defaults: one step (the results are not sent back to the model) and Prism's tool choice.
     *
     * @param  array<int, mixed>  $tools
     */
    public function tools(array $tools): self
    {
        $request = $this->with('tools', [...$this->tools, ...$tools]);
        $request->toolRequest = true;

        return $request;
    }

    /**
     * Adds an MCP server's tools through Relay. Options: tools (use these instead of asking the server).
     *
     * @param  array<string, mixed>  $options
     */
    public function mcp(string $server, array $options = []): self
    {
        $request = $this->with('mcp', [...$this->mcp, ['server' => $server, 'options' => $options]]);
        $request->toolRequest = true;

        return $request;
    }

    /** ToolChoice, or a tool name. */
    public function toolChoice(string|ToolChoice $choice): self
    {
        return $this->with('toolChoice', $choice);
    }

    /** More than one step sends tool results back to the model; a bound sink streams every step. */
    public function maxSteps(int $steps): self
    {
        return $this->with('maxSteps', $steps);
    }

    public function schema(mixed $schema): self
    {
        return $this->with('schema', $schema);
    }

    /**
     * Merged over the node's $providerOptions; this request wins per key.
     *
     * @param  array<string, mixed>  $options
     */
    public function providerOptions(array $options): self
    {
        return $this->with('providerOptions', array_replace($this->providerOptions, $options));
    }

    /** Caches this request's reply (text or structured; never tools or MCP). Null TTL: the node's, else config. */
    public function withCache(?int $ttlMinutes = null): self
    {
        $request = $this->with('cache', $ttlMinutes === null || $ttlMinutes > 0);
        $request->cacheTtl = $ttlMinutes;

        return $request;
    }

    public function withoutCache(): self
    {
        return $this->with('cache', false);
    }

    /** Escape hatch: prefer the node's $model property. */
    public function model(string $model): self
    {
        return $this->with('model', $model);
    }

    /** Escape hatch: prefer the node's $maxTokens property. */
    public function maxTokens(int $maxTokens): self
    {
        return $this->with('maxTokens', $maxTokens);
    }

    /**
     * Exception to the node's $reasoning / $reasoningBudget: a level (off, low, medium, high) or a token budget, for
     * this request only. Throws when the provider cannot apply it; never a silent no-op.
     */
    public function reasoning(?string $level = null, ?int $budget = null): self
    {
        ReasoningMapper::assertValid($level, $budget);
        $request = $this->with('reasoning', $level);
        $request->reasoningBudget = $budget;

        return $request;
    }

    /**
     * How the provider is asked for structured output: auto, json (json_object) or structured (json_schema). Only
     * the OpenAI provider reads it (Prism); any other provider throws before the call. Not applied to a streamed attempt.
     */
    public function structuredMode(string|StructuredMode $mode): self
    {
        $value = $mode instanceof StructuredMode ? strtolower($mode->name) : strtolower($mode);
        if (! in_array($value, ['auto', 'json', 'structured'], true)) {
            throw new \InvalidArgumentException("structuredMode() takes auto, json or structured, got [{$value}].");
        }

        return $this->with('structuredMode', $value);
    }

    /**
     * Hydrates the structured reply into this class (constructor parameters or public properties; nested DTOs, enums,
     * date-times, lists through #[ListOf]). A reply that does not fit is retried like a decode failure; read the
     * object with data() or run()->data.
     *
     * @param  class-string  $class
     */
    public function as(string $class): self
    {
        if (! class_exists($class)) {
            throw new \InvalidArgumentException("as() needs an existing class, got [{$class}].");
        }

        return $this->with('dto', $class);
    }

    public function data(): object
    {
        if ($this->dto === null) {
            throw new \LogicException('data() needs as().');
        }

        return $this->run()->data ?? throw new \LogicException('The reply has no hydrated object.');
    }

    public function text(): string
    {
        return $this->run()->text;
    }

    /**
     * @return array<mixed>
     */
    public function structured(): array
    {
        if ($this->schema === null) {
            throw new \LogicException('structured() needs schema().');
        }

        $response = $this->run();

        return $response->structured ?? throw PrismStructuredDecodingException::make($response->text);
    }

    public function run(): LlmResponse
    {
        return $this->executor->run($this->toCall());
    }

    /**
     * The resolved request without a model call: messages, every setting with its source (config, node, call),
     * the stream decision, the cache decision and key, tools with choice and steps.
     *
     * @return array<string, mixed>
     */
    public function explain(): array
    {
        return $this->executor->explain($this->toCall());
    }

    private function toCall(): LlmCall
    {
        if (($this->dto !== null || $this->structuredMode !== null) && $this->schema === null) {
            throw new \LogicException('as() and structuredMode() need schema().');
        }
        if ($this->schema !== null && $this->toolRequest) {
            throw new \LogicException('schema() cannot be combined with tools() or mcp().');
        }

        $vars = $this->vars;
        $context = null;
        if ($this->context !== null) {
            if (array_key_exists($this->context['as'], $vars)) {
                throw new \LogicException("context() sets the variable [{$this->context['as']}]; vars() sets it too.");
            }
            $format = $this->context['format'];
            $vars[$this->context['as']] = implode($this->context['separator'], array_map(
                fn (array $item): string => $format === null ? $item['content'] : $format->format([...$item['metadata'], 'id' => $item['id'], 'content' => $item['content'], 'score' => $item['score']]),
                $this->context['items'],
            ));
            $context = ['as' => $this->context['as'], 'text' => $vars[$this->context['as']], 'items' => $this->context['items']];
        }

        $prompt = null;
        $messages = [];
        if ($this->template instanceof PromptTemplate) {
            $prompt = $this->template->format($vars);
        } else {
            $formatted = [
                ...($this->template instanceof ChatPromptTemplate ? $this->template->format($vars) : []),
                ...$this->inline,
            ];
            if ($formatted === []) {
                throw new \LogicException('An LLM request needs prompt(), chat(), user() or history().');
            }
            if ($this->system !== null) {
                array_unshift($formatted, ['role' => 'system', 'content' => $this->system]);
            }
            $messages = ChatPromptMessages::toPrismMessages($formatted, $this->media);
        }

        return new LlmCall(
            prompt: $prompt,
            media: $prompt !== null ? $this->media : [],
            messages: $messages,
            system: $this->system,
            schema: $this->schema,
            tools: $this->tools,
            mcp: $this->mcp,
            toolRequest: $this->toolRequest,
            toolChoice: $this->toolChoice,
            maxSteps: $this->maxSteps,
            model: $this->model,
            maxTokens: $this->maxTokens,
            providerOptions: $this->providerOptions,
            reasoning: $this->reasoning,
            reasoningBudget: $this->reasoningBudget,
            structuredMode: $this->structuredMode,
            dto: $this->dto,
            cache: $this->cache,
            cacheTtl: $this->cacheTtl,
            context: $context,
        );
    }

    private function assertNoTemplate(): void
    {
        if ($this->template !== null) {
            throw new \LogicException('An LLM request takes one template: prompt() or chat(), once.');
        }
    }

    /**
     * @param  list<array{role: string, content: string, message?: Message}>  $messages
     */
    private function withInline(array $messages): self
    {
        if ($this->template instanceof PromptTemplate) {
            throw new \LogicException(self::PROMPT_WITH_MESSAGES);
        }

        return $this->with('inline', [...$this->inline, ...$messages]);
    }

    private function with(string $property, mixed $value): self
    {
        $request = clone $this;
        $request->{$property} = $value;

        return $request;
    }
}
