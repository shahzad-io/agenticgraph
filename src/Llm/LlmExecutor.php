<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Llm;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Prism\Relay\Facades\Relay;
use Shahzad\AgenticGraph\Contracts\PrismClient;
use Shahzad\AgenticGraph\Events\ReasoningNotDisabled;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Events\ToolCallCaptured;
use Shahzad\AgenticGraph\Exceptions\MissingModelException;
use Shahzad\AgenticGraph\Exceptions\ReasoningException;
use Shahzad\AgenticGraph\Facades\Trace;
use Shahzad\AgenticGraph\Prompts\MessageSerializer;
use Shahzad\AgenticGraph\Streaming\PrismStreamAdapter;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Tools\ToolRunResult;
use Shahzad\AgenticGraph\Tracing\CaptureDepth;
use Shahzad\AgenticGraph\Tracing\TraceContext;

/**
 * The one path every model call takes: it resolves settings (config, then node, then call), builds the Prism request
 * (timeouts on every path), streams, caches, wraps tools for tracing and dispatches TokensUsed.
 *
 * Streaming matrix: text streams when a sink is bound; a schema streams only when it is a Prism Schema and the
 * request uses prompt() (schema prompt plus a decode fallback); single-step tool calls stream; multi-step tool runs
 * and MCP do not. Caching: off by default; never for tools, MCP, streamed calls or media.
 *
 * @internal Used by {@see LlmRequest} and PrismRouter.
 */
final class LlmExecutor
{
    /**
     * Errors the tool wrapper saw in the current request, keyed by tool name and arguments, in call order.
     *
     * @var array<string, list<string|null>>
     */
    private array $toolErrors = [];

    /** True once the current request wrapped a tool: its calls are then traced by the wrapper, not after the call. */
    private bool $toolsWrapped = false;

    /**
     * @param  array<string, mixed>  $providerOptions  the node's
     */
    public function __construct(
        private readonly PrismClient $client,
        private readonly CacheRepository $cache,
        private readonly Dispatcher $events,
        private readonly string $nodeName,
        private readonly string $cacheScope,
        private readonly ?string $model = null,
        private readonly ?int $maxTokens = null,
        private readonly array $providerOptions = [],
        private readonly bool $trackTokens = true,
        private readonly ?bool $cacheEnabled = null,
        private readonly ?int $cacheTtl = null,
        private readonly ?string $reasoning = null,
        private readonly ?int $reasoningBudget = null,
    ) {}

    /**
     * Every setting with the layer it came from: config, node or call.
     *
     * @return array{
     *     model: array{value: string, source: string},
     *     max_tokens: array{value: int|null, source: string},
     *     provider_options: array{value: array<string, mixed>, source: string},
     *     reasoning: array{value: array{level: string|null, budget: int|null, options: array<string, mixed>, note: string|null}, source: string},
     *     track_tokens: array{value: bool, source: string},
     *     timeout: array{value: int, source: string},
     *     connect_timeout: array{value: int, source: string},
     * }
     */
    public function settings(?LlmCall $call = null): array
    {
        $call ??= new LlmCall;
        $configuredMaxTokens = config('agenticgraph.prism.max_tokens', null);
        $model = match (true) {
            $call->model !== null => ['value' => $call->model, 'source' => 'call'],
            $this->model !== null => ['value' => $this->model, 'source' => 'node'],
            default => ['value' => (string) config('agenticgraph.prism.model'), 'source' => 'config'],
        };
        $reasoning = $this->reasoningSetting($call, $model['value']);
        $providerOptions = array_replace($this->providerOptions, $call->providerOptions);

        return [
            'model' => $model,
            'max_tokens' => match (true) {
                $call->maxTokens !== null => ['value' => $call->maxTokens, 'source' => 'call'],
                $this->maxTokens !== null => ['value' => $this->maxTokens, 'source' => 'node'],
                default => ['value' => is_numeric($configuredMaxTokens) ? (int) $configuredMaxTokens : null, 'source' => 'config'],
            },
            'provider_options' => [
                'value' => array_replace_recursive($providerOptions, $reasoning['value']['options']),
                'source' => match (true) {
                    $call->providerOptions !== [] && $this->providerOptions !== [] => 'node, call',
                    $call->providerOptions !== [] => 'call',
                    default => 'node',
                },
            ],
            'reasoning' => $reasoning,
            'track_tokens' => ['value' => $this->trackTokens, 'source' => 'node'],
            'timeout' => ['value' => (int) config('agenticgraph.api.timeout', 30), 'source' => 'config'],
            'connect_timeout' => ['value' => (int) config('agenticgraph.api.connect_timeout', 10), 'source' => 'config'],
        ];
    }

    /**
     * The reasoning level or budget (call, then node; no config layer) and the provider option it becomes. Throws
     * before any call when the provider cannot apply it.
     *
     * @return array{value: array{level: string|null, budget: int|null, options: array<string, mixed>, note: string|null}, source: string}
     */
    private function reasoningSetting(LlmCall $call, string $model): array
    {
        [$level, $budget, $source] = match (true) {
            $call->reasoning !== null || $call->reasoningBudget !== null => [$call->reasoning, $call->reasoningBudget, 'call'],
            $this->reasoning !== null || $this->reasoningBudget !== null => [$this->reasoning, $this->reasoningBudget, 'node'],
            default => [null, null, 'default'],
        };
        if ($level === null && $budget === null) {
            return ['value' => ['level' => null, 'budget' => null, 'options' => [], 'note' => null], 'source' => $source];
        }

        ReasoningMapper::assertValid($level, $budget);
        $budgets = config('agenticgraph.prism.reasoning.budgets', []);
        $mapped = ReasoningMapper::options(
            self::parseModel($model)[0],
            $level,
            $budget,
            ReasoningMapper::forcesToolChoice($call->toolChoice),
            is_array($budgets) ? $budgets : [],
        );

        return ['value' => ['level' => $level, 'budget' => $budget, ...$mapped], 'source' => $source];
    }

    public function streams(LlmCall $call): bool
    {
        if ($call->stream === false || $this->streamContext() === null) {
            return false;
        }

        if ($call->toolRequest) {
            return $call->mcp === [] && ($call->maxSteps ?? 1) <= 1;
        }

        if ($call->schema !== null) {
            return $call->schema instanceof Schema && $call->prompt !== null;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @return array{enabled: bool, reason: string|null, source: string, ttl_minutes: int, key: string}
     */
    public function cacheDecision(LlmCall $call, ?array $settings = null): array
    {
        $settings ??= $this->settings($call);
        [$on, $source] = match (true) {
            $call->cache !== null => [$call->cache, 'call'],
            $this->cacheEnabled !== null => [$this->cacheEnabled, 'node'],
            default => [$this->cacheTtl !== 0 && (bool) config('agenticgraph.prism.cache_enabled', false), 'config'],
        };

        $reason = match (true) {
            ! $on => 'off',
            $call->toolRequest => 'tools',
            $this->streams($call) => 'streamed',
            MessageSerializer::hasMedia($call->keyMessages()) => 'media',
            default => null,
        };

        return [
            'enabled' => $reason === null,
            'reason' => $reason,
            'source' => $source,
            'ttl_minutes' => $call->cacheTtl ?? $this->cacheTtl ?? (int) config('agenticgraph.prism.cache_ttl', 60),
            'key' => $this->cacheKey($call, $settings),
        ];
    }

    /**
     * The resolved request without a model call. MCP servers are listed by name; their tools are not fetched.
     *
     * @return array<string, mixed>
     */
    public function explain(LlmCall $call): array
    {
        $settings = $this->settings($call);
        $messages = $call->prompt !== null
            ? [...($call->system !== null ? [new SystemMessage($call->system)] : []), new UserMessage($call->prompt, $call->media)]
            : $call->messages;

        return [
            'messages' => MessageSerializer::toArray($messages),
            'settings' => $settings,
            'stream' => $this->streams($call),
            'cache' => $this->cacheDecision($call, $settings),
            'tools' => [
                'names' => $call->toolNames(),
                'mcp' => array_column($call->mcp, 'server'),
                'choice' => $call->toolChoice instanceof \UnitEnum ? $call->toolChoice->name : $call->toolChoice,
                'max_steps' => $call->maxSteps ?? 1,
            ],
            'schema' => $call->schema instanceof Schema ? $call->schema->toArray() : $call->schema,
        ];
    }

    public function run(LlmCall $call): LlmResponse
    {
        $this->toolErrors = [];
        $this->toolsWrapped = false;
        $startTime = microtime(true);
        $settings = $this->settings($call);
        $model = implode(':', self::parseModel($settings['model']['value']));

        try {
            $response = $this->perform($call, $settings, $model, $startTime);
        } catch (PrismException $e) {
            throw $this->reasoningRejection($settings, $e) ?? $e;
        }

        return $this->flagUndisabled($settings, $model, $response);
    }

    /**
     * A vendor 4xx (not rate limits) while a reasoning setting was sent is rethrown with the setting and the options.
     *
     * @param  array<string, mixed>  $settings
     */
    private function reasoningRejection(array $settings, PrismException $e): ?ReasoningException
    {
        $reasoning = $settings['reasoning']['value'];
        $code = $e->getCode();
        if ($e::class !== PrismException::class || ($reasoning['level'] === null && $reasoning['budget'] === null) || $code < 400 || $code > 499 || $code === 429) {
            return null;
        }

        return ReasoningException::rejected(
            $reasoning['budget'] !== null ? "budget {$reasoning['budget']}" : "level [{$reasoning['level']}]",
            $settings['provider_options']['value'],
            $e,
        );
    }

    /**
     * Level off that still reported thought tokens: a warning, an event and a note on the response; not an exception.
     *
     * @param  array<string, mixed>  $settings
     */
    private function flagUndisabled(array $settings, string $model, LlmResponse $response): LlmResponse
    {
        $thoughtTokens = (int) ($response->usage->thoughtTokens ?? 0);
        if ($settings['reasoning']['value']['level'] !== 'off' || $thoughtTokens <= 0) {
            return $response;
        }

        $message = "Reasoning was set to off but {$model} reported {$thoughtTokens} thought tokens.";
        Log::warning($message, ['node' => $this->nodeName, 'options' => $settings['provider_options']['value']]);
        $this->events->dispatch(new ReasoningNotDisabled($this->nodeName, $model, $thoughtTokens));

        return $response->withWarnings([...$response->warnings, $message]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function perform(LlmCall $call, array $settings, string $model, float $startTime): LlmResponse
    {
        if ($call->toolRequest) {
            return $this->runTools($call, $settings, $model, $startTime);
        }

        if ($this->streams($call)) {
            return $call->schema !== null
                ? $this->streamStructured($call, $settings, $model, $startTime)
                : $this->streamText($call, $settings, $model, $startTime);
        }

        $cache = $this->cacheDecision($call, $settings);
        $cacheKey = $cache['enabled'] ? $cache['key'] : null;
        if ($cacheKey !== null && $this->cache->has($cacheKey)) {
            return $this->cacheHit($call, $model, $cacheKey, $startTime);
        }

        $response = $call->schema !== null
            ? $this->structured($call, $settings, $model, $startTime)
            : $this->text($call, $settings, $model, $startTime);

        if ($cacheKey !== null) {
            $this->cache->put($cacheKey, $call->schema !== null ? $response->structured : $response->text, $cache['ttl_minutes'] * 60);
        }

        return $response;
    }

    /**
     * A text request with the resolved model, max tokens, provider options and timeouts.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public function textRequest(?LlmCall $call = null, ?array $settings = null): PendingTextRequest
    {
        $call ??= new LlmCall;
        $request = $this->configure($this->client->text(), $settings ?? $this->settings($call));
        if ($call->prompt !== null) {
            $request->withPrompt($call->prompt, $call->media);
            if ($call->system !== null) {
                $request->withSystemPrompt($call->system);
            }
        } elseif ($call->messages !== []) {
            $request->withMessages($call->messages);
        }

        return $request;
    }

    /**
     * Wraps each Prism tool so its handler runs inside Trace::span (tool-internal HTTP and DB nest under the tool
     * span) and its outcome is recorded for the response's tool results.
     *
     * @param  array<int, mixed>  $tools
     * @return array<int, mixed>
     */
    public function wrapTools(array $tools, string $toolType): array
    {
        $wrapped = [];

        foreach ($tools as $tool) {
            if (! $tool instanceof Tool) {
                $wrapped[] = $tool;

                continue;
            }

            $wrappedTool = $this->cloneToolDefinition($tool);
            $toolName = $tool->name();

            $wrappedTool->using(function (...$args) use ($tool, $toolName, $toolType): string|ToolOutput {
                return $this->executeToolWithTracing($tool, $toolName, $args, $toolType);
            });

            $wrapped[] = $wrappedTool;
            $this->toolsWrapped = true;
        }

        return $wrapped;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function parseModel(string $value): array
    {
        if ($value === '') {
            throw MissingModelException::notConfigured();
        }

        $parts = explode(':', $value, 2);
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            return [$parts[0], $parts[1]];
        }

        throw MissingModelException::missingProvider($value);
    }

    /**
     * @template T of PendingTextRequest|PendingStructuredRequest
     *
     * @param  T  $request
     * @param  array<string, mixed>  $settings
     * @return T
     */
    private function configure(PendingTextRequest|PendingStructuredRequest $request, array $settings): PendingTextRequest|PendingStructuredRequest
    {
        [$provider, $model] = self::parseModel($settings['model']['value']);
        $request->using($provider, $model);

        if ($settings['max_tokens']['value'] !== null) {
            $request->withMaxTokens($settings['max_tokens']['value']);
        }

        if ($settings['provider_options']['value'] !== []) {
            $request->withProviderOptions($settings['provider_options']['value']);
        }

        return $request->withClientOptions([
            'timeout' => $settings['timeout']['value'],
            'connect_timeout' => $settings['connect_timeout']['value'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function text(LlmCall $call, array $settings, string $model, float $startTime): LlmResponse
    {
        $response = $this->textRequest($call, $settings)->asText();
        $this->dispatchUsage($startTime, $model, 'text', $this->resolveUsage($response), $call->promptForTrace(), $response->text);

        return new LlmResponse(text: $response->text, usage: $response->usage, finishReason: $response->finishReason);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function streamText(LlmCall $call, array $settings, string $model, float $startTime): LlmResponse
    {
        $stream = $this->stream($this->textRequest($call, $settings));
        $this->dispatchUsage($startTime, $model, 'text', $stream['usage'], $call->promptForTrace(), $stream['text'], streamed: true);

        return new LlmResponse(text: $stream['text'], usage: $stream['usage'], finishReason: $stream['finishReason']);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function structured(LlmCall $call, array $settings, string $model, float $startTime): LlmResponse
    {
        $request = $this->configure($this->client->structured(), $settings)->withSchema($call->schema);
        if ($call->prompt !== null) {
            $request->withPrompt($call->prompt, $call->media);
            if ($call->system !== null) {
                $request->withSystemPrompt($call->system);
            }
        } else {
            $request->withMessages($call->messages);
        }

        $response = $request->asStructured();
        $traceResponse = $call->structuredTraceAsJson && is_array($response->structured)
            ? json_encode($response->structured, JSON_THROW_ON_ERROR)
            : $response->text;
        $this->dispatchUsage($startTime, $model, 'structured', $this->resolveUsage($response), $call->promptForTrace(), $traceResponse);

        return new LlmResponse(text: $response->text, structured: $response->structured, usage: $response->usage, finishReason: $response->finishReason);
    }

    /**
     * Streams the prompt with the schema appended as instructions, then decodes; a reply that is not a JSON object
     * falls back to one non-streamed structured call.
     *
     * @param  array<string, mixed>  $settings
     */
    private function streamStructured(LlmCall $call, array $settings, string $model, float $startTime): LlmResponse
    {
        assert($call->schema instanceof Schema && $call->prompt !== null);
        $schemaPrompt = $this->buildStructuredPrompt($call->prompt, $call->schema);
        $streamCall = new LlmCall(prompt: $schemaPrompt, media: $call->media, system: $call->system);
        $stream = $this->stream($this->textRequest($streamCall, $settings));
        $this->dispatchUsage($startTime, $model, 'text', $stream['usage'], $schemaPrompt, $stream['text'], streamed: true);

        try {
            $structured = $this->decodeStructuredResponseText($stream['text']);
        } catch (PrismStructuredDecodingException) {
            return $this->structured($call, $settings, $model, $startTime);
        }

        return new LlmResponse(text: $stream['text'], structured: $structured, usage: $stream['usage'], finishReason: $stream['finishReason']);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function runTools(LlmCall $call, array $settings, string $model, float $startTime): LlmResponse
    {
        $tools = $this->wrapTools($call->tools, 'prism');
        foreach ($call->mcp as ['server' => $server, 'options' => $options]) {
            $serverTools = $options['tools'] ?? Relay::tools($server);
            array_push($tools, ...$this->wrapTools(is_array($serverTools) ? array_values($serverTools) : [], 'mcp'));
        }
        [$callType, $toolType] = $call->mcp !== [] ? ['mcp', 'mcp'] : ['tools', 'prism'];

        $request = $this->textRequest($call, $settings)->withTools($tools);
        if ($call->toolChoice !== null) {
            $request->withToolChoice($call->toolChoice);
        }
        if ($call->maxSteps !== null) {
            $request->withMaxSteps($call->maxSteps);
        }

        if ($this->streams($call)) {
            $stream = $this->stream($request);
            $this->dispatchToolCalls($stream['toolCalls'], $toolType);
            $this->dispatchUsage($startTime, $model, $callType, $stream['usage'], $call->promptForTrace(), $stream['text'], streamed: true);

            return new LlmResponse(
                text: $stream['text'],
                toolCalls: $stream['toolCalls'],
                toolResults: $this->toolRunResults($stream['toolResults']),
                usage: $stream['usage'],
                finishReason: $stream['finishReason'],
            );
        }

        $response = $request->asText();
        [$toolCalls, $toolResults] = $this->toolsOfEveryStep($response);
        $this->dispatchToolCalls($toolCalls, $toolType);
        $this->dispatchUsage($startTime, $model, $callType, $this->resolveUsage($response), $call->promptForTrace(), $response->text);

        return new LlmResponse(
            text: $response->text,
            toolCalls: $toolCalls,
            toolResults: $this->toolRunResults($toolResults),
            usage: $response->usage,
            finishReason: $response->finishReason,
        );
    }

    /**
     * Prism's own toolCalls and toolResults hold only the final step's.
     *
     * @return array{0: list<ToolCall>, 1: list<ToolResult>}
     */
    private function toolsOfEveryStep(TextResponse $response): array
    {
        if ($response->steps->isEmpty()) {
            return [array_values($response->toolCalls), array_values($response->toolResults)];
        }

        $toolCalls = [];
        $toolResults = [];
        foreach ($response->steps as $step) {
            array_push($toolCalls, ...$step->toolCalls);
            array_push($toolResults, ...$step->toolResults);
        }

        return [$toolCalls, $toolResults];
    }

    private function cacheHit(LlmCall $call, string $model, string $cacheKey, float $startTime): LlmResponse
    {
        $cached = $this->cache->get($cacheKey);
        $structured = $call->schema !== null && is_array($cached) ? $cached : null;
        $text = $structured !== null ? (string) json_encode($structured) : (is_string($cached) ? $cached : '');

        if ($this->trackTokens) {
            $this->events->dispatch(TokensUsed::fromCacheHit(
                node: $this->nodeName,
                callStartMicrotime: $startTime,
                callDurationMs: (microtime(true) - $startTime) * 1000,
                model: $model,
                prompt: (string) $call->promptForTrace(),
                response: $text,
                cacheKey: $cacheKey,
            ));
        }

        return new LlmResponse(text: $structured !== null ? '' : $text, structured: $structured, cached: true);
    }

    /**
     * Emits every adapted event to the sink, in order, through one adapter for the request (the B2 wire contract).
     *
     * @return array{text: string, usage: Usage|null, finishReason: FinishReason|null, toolCalls: list<ToolCall>, toolResults: list<ToolResult>}
     */
    private function stream(PendingTextRequest $request): array
    {
        $context = $this->streamContext();
        $sink = $context?->sink();
        assert($context !== null && $sink !== null);
        $traceContext = TraceContext::tryGet();
        $adapter = new PrismStreamAdapter(
            session: $context->session(),
            nodeName: $this->nodeName,
            spanId: $traceContext?->getSpanId(),
            parentSpanId: $traceContext?->getParentSpanId()
        );
        $result = ['text' => '', 'usage' => null, 'finishReason' => null, 'toolCalls' => [], 'toolResults' => []];

        foreach ($request->asStream() as $event) {
            if ($event instanceof StreamEndEvent) {
                $result['finishReason'] = $event->finishReason;
                if ($event->usage !== null) {
                    $result['usage'] = $event->usage;
                }
            }
            if ($event instanceof ToolResultEvent) {
                $result['toolResults'][] = $event->toolResult;
            }
            $streamEvent = $adapter->adapt($event);
            if ($streamEvent === null) {
                continue;
            }
            $sink->emit($streamEvent);
            if ($event instanceof ToolCallEvent) {
                $result['toolCalls'][] = $event->toolCall;
            }
            if ($streamEvent->type() === 'text_delta' && is_string($delta = $streamEvent->payload()['delta'] ?? null)) {
                $result['text'] .= $delta;
            }
        }

        return $result;
    }

    private function streamContext(): ?StreamContext
    {
        try {
            $context = app(StreamContext::class);
        } catch (BindingResolutionException) {
            return null;
        }

        return $context->sink() !== null ? $context : null;
    }

    /**
     * Covers the model, max tokens, provider options, system prompt, schema and the canonical message list (role,
     * content, tool calls, tool results).
     *
     * @param  array<string, mixed>  $settings
     */
    private function cacheKey(LlmCall $call, array $settings): string
    {
        $payload = json_encode([
            'model' => $settings['model']['value'],
            'max_tokens' => $settings['max_tokens']['value'],
            'provider_options' => $settings['provider_options']['value'],
            'system' => $call->system,
            'schema' => $call->schema instanceof Schema ? $call->schema->toArray() : $call->schema,
            'messages' => MessageSerializer::toArray($call->keyMessages()),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return sprintf(
            'agenticgraph:prism:%s:%s:%s',
            config('agenticgraph.cache.version', 'v1'),
            $this->cacheScope,
            hash('sha256', (string) $payload)
        );
    }

    /**
     * @param  array{promptTokens: int, completionTokens: int, thoughtTokens: int|null}|Usage|null  $usage
     */
    private function dispatchUsage(float $startTime, string $model, string $callType, array|Usage|null $usage, ?string $prompt, ?string $response, bool $streamed = false): void
    {
        if (! $this->trackTokens || $usage === null) {
            return;
        }

        $this->events->dispatch(TokensUsed::fromLlmCall(
            node: $this->nodeName,
            callStartMicrotime: $startTime,
            callDurationMs: (microtime(true) - $startTime) * 1000,
            promptTokens: $usage instanceof Usage ? (int) $usage->promptTokens : $usage['promptTokens'],
            completionTokens: $usage instanceof Usage ? (int) $usage->completionTokens : $usage['completionTokens'],
            thoughtTokens: $usage instanceof Usage ? $usage->thoughtTokens : $usage['thoughtTokens'],
            callType: $callType,
            model: $model,
            prompt: $prompt,
            response: $response,
            streamed: $streamed,
        ));
    }

    /**
     * The response's usage, else the sum of its steps' usage.
     *
     * @return array{promptTokens: int, completionTokens: int, thoughtTokens: int|null}|null
     */
    private function resolveUsage(object $response): ?array
    {
        if (isset($response->usage)) {
            return [
                'promptTokens' => (int) ($response->usage->promptTokens ?? 0),
                'completionTokens' => (int) ($response->usage->completionTokens ?? 0),
                'thoughtTokens' => isset($response->usage->thoughtTokens) ? (int) $response->usage->thoughtTokens : null,
            ];
        }

        $promptTokens = 0;
        $completionTokens = 0;
        $thoughtTokens = null;
        foreach (property_exists($response, 'steps') && is_iterable($response->steps) ? $response->steps : [] as $step) {
            $promptTokens += (int) ($step->usage->promptTokens ?? 0);
            $completionTokens += (int) ($step->usage->completionTokens ?? 0);
            if (isset($step->usage->thoughtTokens)) {
                $thoughtTokens = ($thoughtTokens ?? 0) + (int) $step->usage->thoughtTokens;
            }
        }

        return $promptTokens === 0 && $completionTokens === 0 ? null : ['promptTokens' => $promptTokens, 'completionTokens' => $completionTokens, 'thoughtTokens' => $thoughtTokens];
    }

    private function buildStructuredPrompt(string $prompt, Schema $schema): string
    {
        $schemaJson = json_encode($schema->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($schemaJson === false) {
            $schemaJson = '{}';
        }

        return $prompt."\n\nReturn only JSON that matches this schema:\n".$schemaJson."\nNo markdown, no extra keys.";
    }

    /**
     * @return array<mixed>
     */
    private function decodeStructuredResponseText(string $responseText): array
    {
        $pattern = '/^```(?:json)?\s*\n?(.*?)\n?```$/s';
        $payload = trim($responseText);
        if (preg_match($pattern, $payload, $matches)) {
            $payload = trim($matches[1]);
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw PrismStructuredDecodingException::make($responseText);
        }

        return is_array($decoded) ? $decoded : throw PrismStructuredDecodingException::make($responseText);
    }

    /**
     * Pairs Prism's results (which carry the tool call id) with the errors the tool wrapper saw, by tool name and arguments.
     *
     * @param  array<int, ToolResult>  $toolResults
     * @return list<ToolRunResult>
     */
    private function toolRunResults(array $toolResults): array
    {
        $runResults = [];
        foreach ($toolResults as $toolResult) {
            $key = $this->toolOutcomeKey($toolResult->toolName, $toolResult->args);
            $error = isset($this->toolErrors[$key]) && $this->toolErrors[$key] !== [] ? array_shift($this->toolErrors[$key]) : null;
            $runResults[] = new ToolRunResult($toolResult->toolCallId, $toolResult->toolName, $toolResult->args, $toolResult->result, $error);
        }

        return $runResults;
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     */
    private function toolOutcomeKey(string $toolName, array $arguments): string
    {
        return $toolName.'|'.json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Traces calls the wrapper did not see (no Prism Tool was wrapped in this request).
     *
     * @param  array<int, ToolCall>  $toolCalls
     */
    private function dispatchToolCalls(array $toolCalls, string $toolType): void
    {
        if ($toolCalls === [] || $this->toolsWrapped) {
            return;
        }

        $traceContext = TraceContext::tryGet();
        if ($traceContext === null) {
            return;
        }

        foreach ($toolCalls as $toolCall) {
            if ($toolCall->name === '') {
                continue;
            }

            $this->events->dispatch(new ToolCallCaptured(
                executionId: $traceContext->getExecutionId(),
                spanId: $traceContext->getSpanId(),
                toolName: $toolCall->name,
                toolCallId: $toolCall->id,
                toolType: $toolType,
                toolProvider: $this->toolProviderFromName($toolCall->name),
                inputSize: $this->payloadSize($toolCall->arguments()),
                outputSize: null,
                durationMs: null,
                startedAt: new \DateTimeImmutable,
                toolRequestId: $toolCall->id,
                status: 'success',
                errorType: null,
                errorMessage: null
            ));
        }
    }

    private function cloneToolDefinition(Tool $tool): Tool
    {
        $wrappedTool = new Tool;
        $wrappedTool->as($tool->name());
        $wrappedTool->for($tool->description());

        foreach ($tool->parameters() as $name => $schema) {
            $required = in_array($name, $tool->requiredParameters(), true);
            $wrappedTool->withParameter($schema, $required);
        }

        if ($tool->isConcurrent()) {
            $wrappedTool->concurrent(true);
        }

        // The original tool already applied its own error handling: a failure arrives here as an exception carrying
        // the message it chose (see executeToolWithTracing), which goes to the model unchanged, as a failed result.
        if ($tool->failedHandler() === false) {
            $wrappedTool->withoutErrorHandling();
        } else {
            $wrappedTool->failed(fn (\Throwable $e): string => $e->getMessage());
        }

        return $wrappedTool;
    }

    /**
     * @param  array<int|string, mixed>  $args
     */
    private function executeToolWithTracing(Tool $tool, string $toolName, array $args, string $toolType): string|ToolOutput
    {
        $startedAt = new \DateTimeImmutable;
        $start = microtime(true);
        $toolSpanId = null;

        $attributes = array_merge([
            'tool.type' => $toolType,
            'tool.provider' => $this->toolProviderFromName($toolName),
            'tool.input_size' => $this->payloadSize($args),
        ], CaptureDepth::toolArgsForSpan($args));

        try {
            $output = Trace::span($toolName, function () use ($tool, $args, &$toolSpanId): string|ToolOutput|ToolError {
                $toolSpanId = TraceContext::tryGet()?->getSpanId();

                return $tool->handle(...$args);
            }, $attributes, 'tool');
        } catch (\Throwable $e) {
            $this->toolErrors[$this->toolOutcomeKey($toolName, $args)][] = $e->getMessage();
            $this->dispatchWrappedToolCallEvent($toolName, $toolType, $args, null, (microtime(true) - $start) * 1000, $startedAt, 'failed', get_class($e), $e->getMessage(), $toolSpanId);
            throw $e;
        }

        $durationMs = (microtime(true) - $start) * 1000;
        if ($output instanceof ToolError) {
            [$result, $status, $errorType, $errorMessage] = [$output->message, 'failed', 'tool_error', $output->message];
        } else {
            $result = $output instanceof ToolOutput ? $output->result : $output;
            [$status, $errorType, $errorMessage] = $this->detectToolError($result);
        }
        $this->toolErrors[$this->toolOutcomeKey($toolName, $args)][] = $status === 'failed' ? $errorMessage : null;
        $this->dispatchWrappedToolCallEvent($toolName, $toolType, $args, $result, $durationMs, $startedAt, $status, $errorType, $errorMessage, $toolSpanId);

        if ($output instanceof ToolError) {
            // The wrapped tool's failed handler turns this back into a ToolError with the same message.
            throw new \RuntimeException($output->message);
        }

        return $output;
    }

    /**
     * @param  array<int|string, mixed>  $args
     */
    private function dispatchWrappedToolCallEvent(
        string $toolName,
        string $toolType,
        array $args,
        ?string $result,
        float $durationMs,
        \DateTimeImmutable $startedAt,
        string $status,
        ?string $errorType,
        ?string $errorMessage,
        ?string $toolSpanId,
    ): void {
        $traceContext = TraceContext::tryGet();
        if ($traceContext === null) {
            return;
        }

        $this->events->dispatch(new ToolCallCaptured(
            executionId: $traceContext->getExecutionId(),
            spanId: $traceContext->getSpanId(),
            toolName: $toolName,
            toolCallId: null,
            toolType: $toolType,
            toolProvider: $this->toolProviderFromName($toolName),
            inputSize: $this->payloadSize($args),
            outputSize: $this->payloadSize($result),
            durationMs: $durationMs,
            startedAt: $startedAt,
            toolRequestId: null,
            status: $status,
            errorType: $errorType,
            errorMessage: $errorMessage,
            toolSpanId: $toolSpanId,
            spanAlreadyExported: true,
        ));
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function detectToolError(string $result): array
    {
        if (! str_starts_with($result, 'ERROR:') && ! str_starts_with($result, 'Tool execution error:')) {
            return ['success', null, null];
        }

        $message = trim($result);
        $type = 'tool_error';
        $lower = strtolower($message);
        if (str_contains($lower, 'transport') || str_contains($lower, 'connection') || str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            $type = 'connection';
        }

        return ['failed', $type, $message];
    }

    private function toolProviderFromName(string $toolName): ?string
    {
        if (! str_contains($toolName, '__')) {
            return null;
        }

        $provider = explode('__', $toolName)[1] ?? null;

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    private function payloadSize(mixed $payload): ?int
    {
        if ($payload === null) {
            return null;
        }

        if (is_string($payload)) {
            return strlen($payload);
        }

        $encoded = json_encode($payload);

        return is_string($encoded) ? strlen($encoded) : null;
    }
}
