<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Llm;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Prism\Prism\Text\Step;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Prism\Relay\Facades\Relay;
use Shahzad\AgenticGraph\Contracts\PrismClient;
use Shahzad\AgenticGraph\Events\LlmCallFailed;
use Shahzad\AgenticGraph\Events\ReasoningNotDisabled;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Events\ToolCallCaptured;
use Shahzad\AgenticGraph\Exceptions\MissingModelException;
use Shahzad\AgenticGraph\Exceptions\ReasoningException;
use Shahzad\AgenticGraph\Exceptions\StructuredHydrationException;
use Shahzad\AgenticGraph\Facades\Trace;
use Shahzad\AgenticGraph\Prompts\MessageSerializer;
use Shahzad\AgenticGraph\Streaming\PrismStreamAdapter;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Testing\LlmFake;
use Shahzad\AgenticGraph\Tools\ToolRunResult;
use Shahzad\AgenticGraph\Tracing\CaptureDepth;
use Shahzad\AgenticGraph\Tracing\TraceContext;

/**
 * The one path every model call takes: it resolves settings (config, then node, then call), builds the Prism request
 * (timeouts on every path), streams, caches, wraps tools for tracing and dispatches TokensUsed (LlmCallFailed when the provider throws).
 *
 * Streaming matrix: text streams when a sink is bound; a schema streams only when it is a Prism Schema and the
 * request uses prompt() (schema prompt; a reply that fails is retried non-streamed, see structured.retries); tool runs
 * stream every step, MCP included. Caching: off by default; never for tools, MCP, streamed calls or media.
 *
 * @internal Used by {@see LlmRequest} and PrismRouter.
 */
final class LlmExecutor
{
    private const DEFAULT_STRUCTURED_RETRIES = 1;

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
        private readonly ?float $temperature = null,
        private readonly string $temperatureConfig = 'agenticgraph.prism.temperature',
    ) {}

    /**
     * Every setting with the layer it came from: config, node or call.
     *
     * @return array{
     *     model: array{value: string, source: string},
     *     max_tokens: array{value: int|null, source: string},
     *     temperature: array{value: float|null, source: string},
     *     provider_options: array{value: array<string, mixed>, source: string},
     *     reasoning: array{value: array{level: string|null, budget: int|null, options: array<string, mixed>, note: string|null}, source: string},
     *     structured_mode: array{value: string|null, source: string},
     *     structured_retries: array{value: int, source: string},
     *     track_tokens: array{value: bool, source: string},
     *     timeout: array{value: int, source: string},
     *     connect_timeout: array{value: int, source: string},
     * }
     */
    public function settings(?LlmCall $call = null): array
    {
        $call ??= new LlmCall;
        $configuredMaxTokens = config('agenticgraph.prism.max_tokens', null);
        $configuredTemperature = config($this->temperatureConfig, null);
        $model = match (true) {
            $call->model !== null => ['value' => $call->model, 'source' => 'call'],
            $this->model !== null => ['value' => $this->model, 'source' => 'node'],
            default => ['value' => (string) config('agenticgraph.prism.model'), 'source' => 'config'],
        };
        $reasoning = $this->reasoningSetting($call, $model['value']);
        $providerOptions = array_replace($this->providerOptions, $call->providerOptions);
        $temperature = match (true) {
            $call->temperature !== null => ['value' => $call->temperature, 'source' => 'call'],
            $this->temperature !== null => ['value' => $this->temperature, 'source' => 'node'],
            default => ['value' => is_numeric($configuredTemperature) ? (float) $configuredTemperature : null, 'source' => 'config'],
        };
        if ($temperature['value'] !== null && ($temperature['value'] < 0 || $temperature['value'] > 2)) {
            $where = $temperature['source'] === 'config' ? "config ({$this->temperatureConfig})" : $temperature['source'];
            throw new \InvalidArgumentException("The temperature from {$where} must be 0 to 2, got [{$temperature['value']}].");
        }

        return [
            'model' => $model,
            'max_tokens' => match (true) {
                $call->maxTokens !== null => ['value' => $call->maxTokens, 'source' => 'call'],
                $this->maxTokens !== null => ['value' => $this->maxTokens, 'source' => 'node'],
                default => ['value' => is_numeric($configuredMaxTokens) ? (int) $configuredMaxTokens : null, 'source' => 'config'],
            },
            'temperature' => $temperature,
            'provider_options' => [
                'value' => array_replace_recursive($providerOptions, $reasoning['value']['options']),
                'source' => match (true) {
                    $call->providerOptions !== [] && $this->providerOptions !== [] => 'node, call',
                    $call->providerOptions !== [] => 'call',
                    default => 'node',
                },
            ],
            'reasoning' => $reasoning,
            'structured_mode' => $this->structuredModeSetting($call, $model['value']),
            'structured_retries' => $call->structuredRetries !== null
                ? ['value' => max(0, $call->structuredRetries), 'source' => 'call']
                : ['value' => max(0, (int) config('agenticgraph.prism.structured.retries', self::DEFAULT_STRUCTURED_RETRIES)), 'source' => 'config'],
            'track_tokens' => ['value' => $this->trackTokens, 'source' => 'node'],
            'timeout' => $call->timeout !== null
                ? ['value' => $call->timeout, 'source' => 'call']
                : ['value' => (int) config('agenticgraph.api.timeout', 30), 'source' => 'config'],
            'connect_timeout' => $call->connectTimeout !== null
                ? ['value' => $call->connectTimeout, 'source' => 'call']
                : ['value' => (int) config('agenticgraph.api.connect_timeout', 10), 'source' => 'config'],
        ];
    }

    /**
     * Prism's OpenAI handler is the only one that reads a structured mode; any other provider would ignore it silently.
     *
     * @return array{value: string|null, source: string}
     */
    private function structuredModeSetting(LlmCall $call, string $model): array
    {
        if ($call->structuredMode === null) {
            return ['value' => null, 'source' => 'default'];
        }

        $provider = self::parseModel($model)[0];
        if ($provider !== 'openai') {
            throw new \LogicException("structuredMode() is read only by the [openai] provider (and OpenAI-compatible gateways through it); the [{$provider}] provider would ignore it. Remove it for this call.");
        }

        return ['value' => $call->structuredMode, 'source' => 'call'];
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
        $off = config('agenticgraph.prism.reasoning.off', []);
        $mapped = ReasoningMapper::options(
            self::parseModel($model)[0],
            $level,
            $budget,
            ReasoningMapper::forcesToolChoice($call->toolChoice),
            is_array($budgets) ? $budgets : [],
            is_array($off) ? $off : [],
        );

        return ['value' => ['level' => $level, 'budget' => $budget, ...$mapped], 'source' => $source];
    }

    public function streams(LlmCall $call): bool
    {
        if ($call->stream === false || $this->streamContext() === null) {
            return false;
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
            'context' => $call->context !== null ? ['var' => $call->context['as'], 'count' => count($call->context['items']), 'text' => $call->context['text']] : null,
            'prompt' => $call->promptFile,
        ];
    }

    /**
     * @param  array{vars?: array<string, mixed>, template_vars?: list<string>, history?: list<array{role: string, content: string}>}  $builder  what LlmRequest resolved before formatting, for Llm::fake()
     */
    public function run(LlmCall $call, array $builder = []): LlmResponse
    {
        $this->toolErrors = [];
        $this->toolsWrapped = false;
        $startTime = microtime(true);
        $settings = $this->settings($call);
        $model = implode(':', self::parseModel($settings['model']['value']));
        if (app()->bound(LlmFake::class)) {
            app(LlmFake::class)->record(['node' => $this->nodeName, 'vars' => [], 'template_vars' => [], 'history' => [], ...$builder, ...$this->explain($call), 'context' => $call->context]);
        }

        try {
            $response = $this->perform($call, $settings, $model, $startTime);
            if ($call->context !== null) {
                $response = $response->withSources($call->sources());
            }
        } catch (PrismException $e) {
            $thrown = $this->reasoningRejection($settings, $e) ?? $e;
            $this->dispatchFailure($call, $model, $startTime, $thrown);

            throw $thrown;
        }

        return $this->flagUndisabled($settings, $model, $response);
    }

    /**
     * A provider exception becomes a failed llm span. A reply that does not decode is not a failed call: the
     * provider answered, and the attempts that reported usage are already dispatched.
     */
    private function dispatchFailure(LlmCall $call, string $model, float $startTime, \Throwable $e): void
    {
        if (! $this->trackTokens || $e instanceof PrismStructuredDecodingException) {
            return;
        }

        $this->events->dispatch(LlmCallFailed::fromException(
            node: $this->nodeName,
            callStartMicrotime: $startTime,
            callType: match (true) {
                $call->toolRequest => $call->mcp !== [] ? 'mcp' : 'tools',
                $call->schema !== null => 'structured',
                default => 'text',
            },
            model: $model,
            exception: $e,
            streamed: $this->streams($call),
            contextCount: $call->context !== null ? count($call->context['items']) : null,
            contextIds: $call->context !== null ? self::contextIds($call->context) : null,
            promptFile: $call->promptFile,
        ));
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
            $settings['temperature']['value'],
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
                ? $this->structuredWithRetry($call, $settings, $model, $startTime, streamFirst: true)
                : $this->streamText($call, $settings, $model, $startTime);
        }

        $cache = $this->cacheDecision($call, $settings);
        $cacheKey = $cache['enabled'] ? $cache['key'] : null;
        if ($cacheKey !== null && $this->cache->has($cacheKey)) {
            return $this->cacheHit($call, $model, $cacheKey, $startTime);
        }

        $response = $call->schema !== null
            ? $this->structuredWithRetry($call, $settings, $model, $startTime)
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

        if ($settings['temperature']['value'] !== null) {
            $request->usingTemperature($settings['temperature']['value']);
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
        $this->dispatchUsage($startTime, $model, 'text', $this->resolveUsage($response), $call->promptForTrace(), $response->text, call: $call);

        return new LlmResponse(text: $response->text, usage: $response->usage, finishReason: $response->finishReason);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function streamText(LlmCall $call, array $settings, string $model, float $startTime): LlmResponse
    {
        $stream = $this->stream($this->textRequest($call, $settings));
        $this->dispatchUsage($startTime, $model, 'text', $stream['usage'], $call->promptForTrace(), $stream['text'], streamed: true, call: $call);

        return new LlmResponse(text: $stream['text'], usage: $stream['usage'], finishReason: $stream['finishReason']);
    }

    /**
     * Attempt 1 (streamed when asked), then up to structured.retries more non-streamed attempts, each with the failed
     * reply and the error appended. A failure is a reply that does not decode or does not fit as(). Every attempt that
     * reports usage is dispatched; the response sums them, or has no usage at all when an attempt's is unknown.
     *
     * @param  array<string, mixed>  $settings
     */
    private function structuredWithRetry(LlmCall $call, array $settings, string $model, float $startTime, bool $streamFirst = false): LlmResponse
    {
        $retries = $settings['structured_retries']['value'];
        $usages = [];
        $feedback = null;

        for ($attempt = 1; ; $attempt++) {
            $response = $streamFirst && $attempt === 1
                ? $this->streamedAttempt($call, $settings, $model, $startTime)
                : $this->structuredAttempt($call, $settings, $model, $startTime, $feedback);
            $usages[] = $response->usage;

            $data = null;
            try {
                if ($response->structured === null) {
                    throw PrismStructuredDecodingException::make($response->text);
                }
                $data = $call->dto !== null ? DtoHydrator::hydrate($call->dto, $response->structured) : null;
            } catch (PrismStructuredDecodingException|StructuredHydrationException $e) {
                if ($attempt > $retries) {
                    throw $e;
                }
                $feedback = [$response->text, $e instanceof StructuredHydrationException ? $e->getMessage() : 'The reply was not a valid JSON object.'];

                continue;
            }

            $known = ! in_array(null, $usages, true);

            return new LlmResponse(
                text: $response->text,
                structured: $response->structured,
                usage: $known ? $this->sumUsage($usages) : null,
                finishReason: $response->finishReason,
                warnings: $known ? [] : ['Token usage is unknown: Prism discards the usage of a structured attempt that fails to decode, so only the attempts it reported are in the trace.'],
                data: $data,
                attempts: $attempt,
            );
        }
    }

    /**
     * One non-streamed structured call. A reply Prism cannot decode comes back as structured null with the reply text
     * and no usage (Prism throws and drops the usage), so the caller can retry.
     *
     * @param  array<string, mixed>  $settings
     * @param  array{0: string, 1: string}|null  $feedback  the failed reply and the error to show the model
     */
    private function structuredAttempt(LlmCall $call, array $settings, string $model, float $startTime, ?array $feedback = null): LlmResponse
    {
        $request = $this->configure($this->client->structured(), $settings)->withSchema($call->schema);
        if ($settings['structured_mode']['value'] !== null) {
            $request->usingStructuredMode(constant(StructuredMode::class.'::'.ucfirst($settings['structured_mode']['value'])));
        }
        $messages = $call->prompt !== null ? [new UserMessage($call->prompt, $call->media)] : $call->messages;
        if ($feedback !== null) {
            $messages = [
                ...$messages,
                new AssistantMessage(mb_strimwidth($feedback[0], 0, 4000, '...') ?: '(empty reply)'),
                new UserMessage("Your previous reply was rejected: {$feedback[1]}\nReply again with only JSON that matches the schema."),
            ];
        }
        if ($feedback === null && $call->prompt !== null) {
            $request->withPrompt($call->prompt, $call->media);
        } else {
            $request->withMessages($messages);
        }
        if ($call->prompt !== null && $call->system !== null) {
            $request->withSystemPrompt($call->system);
        }

        try {
            $response = $request->asStructured();
        } catch (PrismStructuredDecodingException $e) {
            return new LlmResponse(text: str_replace('Structured object could not be decoded. Received: ', '', $e->getMessage()));
        }

        $traceResponse = $call->structuredTraceAsJson && is_array($response->structured)
            ? json_encode($response->structured, JSON_THROW_ON_ERROR)
            : $response->text;
        $this->dispatchUsage($startTime, $model, 'structured', $this->resolveUsage($response), $call->promptForTrace(), $traceResponse, call: $call);

        return new LlmResponse(text: $response->text, structured: $response->structured, usage: $response->usage, finishReason: $response->finishReason);
    }

    /**
     * Streams the prompt with the schema appended as instructions, then decodes (structured null when it does not).
     *
     * @param  array<string, mixed>  $settings
     */
    private function streamedAttempt(LlmCall $call, array $settings, string $model, float $startTime): LlmResponse
    {
        assert($call->schema instanceof Schema && $call->prompt !== null);
        $schemaPrompt = $this->buildStructuredPrompt($call->prompt, $call->schema);
        $streamCall = new LlmCall(prompt: $schemaPrompt, media: $call->media, system: $call->system);
        $stream = $this->stream($this->textRequest($streamCall, $settings));
        $this->dispatchUsage($startTime, $model, 'text', $stream['usage'], $schemaPrompt, $stream['text'], streamed: true, call: $call);

        try {
            $structured = $this->decodeStructuredResponseText($stream['text']);
        } catch (PrismStructuredDecodingException) {
            $structured = null;
        }

        return new LlmResponse(text: $stream['text'], structured: $structured, usage: $stream['usage'], finishReason: $stream['finishReason']);
    }

    /**
     * @param  list<Usage|null>  $usages  all known
     */
    private function sumUsage(array $usages): Usage
    {
        $sum = fn (string $field): ?int => array_reduce($usages, fn (?int $carry, ?Usage $u): ?int => $u?->{$field} === null ? $carry : ($carry ?? 0) + $u->{$field}, null);

        return new Usage((int) $sum('promptTokens'), (int) $sum('completionTokens'), $sum('cacheWriteInputTokens'), $sum('cacheReadInputTokens'), $sum('thoughtTokens'));
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

        $streamed = $this->streams($call);
        if ($streamed) {
            $stream = $this->stream($request);
            [$text, $usage, $finishReason, $raw] = [$stream['text'], $stream['usage'], $stream['finishReason'], $stream['steps']];
            $traceUsage = $usage;
        } else {
            $response = $request->asText();
            [$text, $usage, $finishReason, $traceUsage] = [$response->text, $response->usage, $response->finishReason, $this->resolveUsage($response)];
            $raw = $response->steps->isEmpty()
                ? [['text' => $response->text, 'toolCalls' => $response->toolCalls, 'toolResults' => $response->toolResults, 'usage' => $response->usage, 'finishReason' => $response->finishReason]]
                : $response->steps->map(fn (Step $step): array => ['text' => $step->text, 'toolCalls' => $step->toolCalls, 'toolResults' => $step->toolResults, 'usage' => $step->usage, 'finishReason' => $step->finishReason])->all();
        }

        // Pairs results with wrapper errors step by step, in call order (toolRunResults consumes them).
        $steps = array_map(fn (array $step): LlmStep => new LlmStep($step['text'], array_values($step['toolCalls']), $this->toolRunResults(array_values($step['toolResults'])), $step['usage'], $step['finishReason']), array_values($raw));
        $toolCalls = array_merge(...array_map(fn (LlmStep $step): array => $step->toolCalls, $steps));
        $this->dispatchToolCalls($toolCalls, $toolType);
        $this->dispatchUsage($startTime, $model, $callType, $traceUsage, $call->promptForTrace(), $text, streamed: $streamed, call: $call);

        return new LlmResponse(
            text: $text,
            toolCalls: $toolCalls,
            toolResults: array_merge(...array_map(fn (LlmStep $step): array => $step->toolResults, $steps)),
            usage: $usage,
            finishReason: $finishReason,
            steps: $steps,
        );
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
                contextCount: $call->context !== null ? count($call->context['items']) : null,
                contextIds: $call->context !== null ? self::contextIds($call->context) : null,
                promptFile: $call->promptFile,
            ));
        }

        // A cached reply that no longer fits as() throws; the entry is left alone.
        $data = $call->dto !== null && $structured !== null ? DtoHydrator::hydrate($call->dto, $structured) : null;

        return new LlmResponse(text: $structured !== null ? '' : $text, structured: $structured, cached: true, data: $data);
    }

    /**
     * Emits every adapted event to the sink, in order, through one adapter for the request (the stream wire contract).
     * Text is the final step's, as non-streamed; usage and finish reason are the request's (StreamEnd), each step keeps its own.
     *
     * @return array{text: string, usage: Usage|null, finishReason: FinishReason|null, steps: list<array{text: string, toolCalls: list<ToolCall>, toolResults: list<ToolResult>, usage: Usage|null, finishReason: FinishReason|null}>}
     */
    private function stream(PendingTextRequest $request): array
    {
        $context = $this->streamContext();
        $sink = $context?->sink();
        assert($context !== null && $sink !== null);
        $traceContext = TraceContext::tryGet();
        $adapter = new PrismStreamAdapter(
            session: $context->session(),
            nodeName: $context->nodeName() ?? $this->nodeName,
            spanId: $traceContext?->getSpanId(),
            parentSpanId: $traceContext?->getParentSpanId()
        );
        $result = ['text' => '', 'usage' => null, 'finishReason' => null, 'steps' => []];
        $blank = ['text' => '', 'toolCalls' => [], 'toolResults' => [], 'usage' => null, 'finishReason' => null];
        $step = $blank;
        $error = null;

        foreach ($request->asStream() as $event) {
            if ($event instanceof StepStartEvent || ($result['steps'] === [] && ! $event instanceof StreamStartEvent)) {
                $result['steps'][] = $blank;
            }
            if ($result['steps'] !== []) {
                $step = &$result['steps'][array_key_last($result['steps'])];
            }
            if ($event instanceof ErrorEvent) {
                $error = $event;
            }
            if ($event instanceof StepFinishEvent) {
                [$step['usage'], $step['finishReason']] = [$event->usage, $event->finishReason];
            }
            if ($event instanceof StreamEndEvent) {
                $result['finishReason'] = $event->finishReason;
                if ($event->usage !== null) {
                    $result['usage'] = $event->usage;
                }
            }
            if ($event instanceof ToolResultEvent) {
                $step['toolResults'][] = $event->toolResult;
            }
            $streamEvent = $adapter->adapt($event);
            if ($streamEvent === null) {
                continue;
            }
            $sink->emit($streamEvent);
            if ($event instanceof ToolCallEvent) {
                $step['toolCalls'][] = $event->toolCall;
            }
            if ($streamEvent->type() === 'text_delta' && is_string($delta = $streamEvent->payload()['delta'] ?? null)) {
                $step['text'] .= $delta;
            }
        }
        unset($step);
        $result['text'] = $result['steps'] === [] ? '' : $result['steps'][array_key_last($result['steps'])]['text'];

        // Prism 0.102 ends an in-stream error as a normal stream with finish reason Error; a non-streamed call throws.
        if ($result['finishReason'] === FinishReason::Error) {
            throw new PrismException('The stream ended with an error'.($error instanceof ErrorEvent ? " ({$error->errorType}): {$error->message}" : '.'));
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
     * Covers the model, max tokens, temperature (when set), provider options, system prompt, schema and the canonical message list (role,
     * content, tool calls, tool results).
     *
     * @param  array<string, mixed>  $settings
     */
    private function cacheKey(LlmCall $call, array $settings): string
    {
        $payload = json_encode([
            ...($settings['structured_mode']['value'] !== null ? ['structured_mode' => $settings['structured_mode']['value']] : []),
            ...($settings['temperature']['value'] !== null ? ['temperature' => $settings['temperature']['value']] : []),
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
     * @param  array{promptTokens: int, completionTokens: int, thoughtTokens: int|null, cacheReadTokens: int|null, cacheWriteTokens: int|null}|Usage|null  $usage
     */
    private function dispatchUsage(float $startTime, string $model, string $callType, array|Usage|null $usage, ?string $prompt, ?string $response, bool $streamed = false, ?LlmCall $call = null): void
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
            cacheReadTokens: $usage instanceof Usage ? $usage->cacheReadInputTokens : $usage['cacheReadTokens'],
            cacheWriteTokens: $usage instanceof Usage ? $usage->cacheWriteInputTokens : $usage['cacheWriteTokens'],
            callType: $callType,
            model: $model,
            prompt: $prompt,
            response: $response,
            streamed: $streamed,
            contextCount: $call?->context !== null ? count($call->context['items']) : null,
            contextIds: $call?->context !== null ? self::contextIds($call->context) : null,
            promptFile: $call?->promptFile,
        ));
    }

    /**
     * The llm span's context.ids: the first 20 item ids, as strings.
     *
     * @param  array{items: list<array{id: string|int}>}  $context
     * @return list<string>
     */
    private static function contextIds(array $context): array
    {
        return array_map(fn (array $item): string => (string) $item['id'], array_slice($context['items'], 0, 20));
    }

    /**
     * The response's usage, else the sum of its steps' usage.
     *
     * @return array{promptTokens: int, completionTokens: int, thoughtTokens: int|null, cacheReadTokens: int|null, cacheWriteTokens: int|null}|null
     */
    private function resolveUsage(object $response): ?array
    {
        if (isset($response->usage)) {
            return [
                'promptTokens' => (int) ($response->usage->promptTokens ?? 0),
                'completionTokens' => (int) ($response->usage->completionTokens ?? 0),
                'thoughtTokens' => isset($response->usage->thoughtTokens) ? (int) $response->usage->thoughtTokens : null,
                'cacheReadTokens' => isset($response->usage->cacheReadInputTokens) ? (int) $response->usage->cacheReadInputTokens : null,
                'cacheWriteTokens' => isset($response->usage->cacheWriteInputTokens) ? (int) $response->usage->cacheWriteInputTokens : null,
            ];
        }

        $promptTokens = 0;
        $completionTokens = 0;
        $thoughtTokens = null;
        $cacheReadTokens = null;
        $cacheWriteTokens = null;
        foreach (property_exists($response, 'steps') && is_iterable($response->steps) ? $response->steps : [] as $step) {
            $promptTokens += (int) ($step->usage->promptTokens ?? 0);
            $completionTokens += (int) ($step->usage->completionTokens ?? 0);
            if (isset($step->usage->thoughtTokens)) {
                $thoughtTokens = ($thoughtTokens ?? 0) + (int) $step->usage->thoughtTokens;
            }
            if (isset($step->usage->cacheReadInputTokens)) {
                $cacheReadTokens = ($cacheReadTokens ?? 0) + (int) $step->usage->cacheReadInputTokens;
            }
            if (isset($step->usage->cacheWriteInputTokens)) {
                $cacheWriteTokens = ($cacheWriteTokens ?? 0) + (int) $step->usage->cacheWriteInputTokens;
            }
        }

        return $promptTokens === 0 && $completionTokens === 0 ? null : ['promptTokens' => $promptTokens, 'completionTokens' => $completionTokens, 'thoughtTokens' => $thoughtTokens, 'cacheReadTokens' => $cacheReadTokens, 'cacheWriteTokens' => $cacheWriteTokens];
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
