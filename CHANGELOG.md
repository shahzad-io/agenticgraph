# Changelog

## 0.2.0 (unreleased)

### Router token cap, failed model calls, waiting traces

- Changed: `PrismRouter`'s routing call is no longer fixed at 100 output tokens. It sends `agenticgraph.router.max_tokens` (`AGENTICGRAPH_ROUTER_MAX_TOKENS`, default 1024, 1024 in code when unset) and `agenticgraph.router.reasoning` (`AGENTICGRAPH_ROUTER_REASONING`, default none). A subclass sets `protected ?int $maxTokens` or `protected ?string $reasoning`, as on `PrismNode`. On a reasoning model, reasoning tokens count against the cap: 100 made routing fall back to the default route. The retry loop is unchanged.
- New: `RouteFellBack` event (`router`, `route`, `reason`: `missing_route_key`, `invalid_route` or `routing_failed`, `error`) and a warning log ("PrismRouter fell back to the default route") whenever `PrismRouter` takes its default route. `route.fallback` on the route span stays.
- **BREAKING:** a `PrismRouter` with no default route that gets no usable decision (no route key, an unknown route, or every retry failed) throws `RoutingException` (`router`, `reason`, `reply`: the reply JSON, or the last error) instead of returning an empty route. A bad reply is not retried. Set a default route to keep the old tolerance. Retries used up threw a `RuntimeException` before; `RoutingException` extends it.
- New: `LlmCallFailed` event, dispatched when the provider throws (a `PrismException`) before the exception is rethrown unchanged. Tracing writes it as an `llm` span with `status: failed`, `status_code: ERROR`, `error`, and null `prompt_tokens`, `completion_tokens`, `total_tokens` and `cost_usd`. A failed call has no `llm_calls` row: its cost is unknown, and the provider may still bill it. Each router retry is one failed span. A structured reply that does not decode is not a failed call. `TokensUsed` is unchanged.
- Changed: the merged trace of a run still `running` or waiting at an interrupt has no `ended_at` and no `duration_ms`. It keeps `active_ms` (time so far). That was the schema's rule already; the merger set both from the last segment's end.
- Trace wire, additive (v1.1): `llm_calls` rows carry nullable `thought_tokens`, `cache_read_tokens`, `cache_write_tokens` (the call span's) and `prompt_name`, `prompt_version`, `prompt_hash` (the call's prompt file). Llm spans may carry `error`, with null counts and cost, when `status_code` is `ERROR`. Wire doc, JSON Schema, key registry and freeze test updated. OTLP mapping unchanged: a failed llm span has error status and exception attributes and no usage attributes.

### Trace privacy defaults

- **BREAKING:** the default `tracing.capture.state` is now `keys` (was `diff`). Node spans' `state_diff.values` carry, per changed top-level key, `{type, value_type}` (`added`, `changed` or `removed`, and the PHP type of the value): no values and no nested keys. Anyone relying on state values in traces (AgenticPulse users, log or OTLP readers) sets `AGENTICGRAPH_CAPTURE_STATE=diff` to keep today's values, or `full`. An app with a published `config/agenticgraph.php` keeps the default written there.
- Fixed: `capture.state=error_only` captured nothing on a failure. A failed trace now carries `trace.final_state`, the state at the failure, under `error_only` and `full`; on success `error_only` sends keys.
- Changed: `capture.max_length` also bounds state values under `diff`, `full` and `error_only` (node values, `initial_state`, `final_state`): a long string is truncated, an array or object whose JSON is longer becomes that JSON truncated.
- New: `capture.errors` (`AGENTICGRAPH_CAPTURE_ERRORS`): `message` (default, as before) or `class`, which sends the exception class without its message on graph, node, tool, http and custom spans and on `trace.error`.
- Trace wire, additive (v1.1): a failed trace may carry `final_state`; `error.message` is optional (`required: ["type"]`). Wire doc, JSON Schema and freeze test updated.
- Docs: the config comments name what is always sent and point to https://pulse.agenticgraph.dev for keys (`ap_live_` / `ap_test_`).

### Octane

- Fixed: under Octane the first traced run on each worker was split in two segments (the graph's spans in one, `Trace::span` and vector spans in another). The event dispatcher builds `TracingListener` from the booted app, which autowired that app's exporter into it; it is now bound without arguments and resolves the request's exporter per event.
- Fixed: `CachingMiddleware` returned `__PHP_Incomplete_Class` (a `TypeError`) on a cache hit with Laravel 13's default `cache.serializable_classes = false`. It now caches the state as a plain array (`State::toStoragePayload()`), so entries written by the old code read as a miss once; flush the cache after upgrading.
- Changed: the unknown-model pricing warning is logged once per request or queued job (was once per process). `ModelPricingCalculator` is bound `scoped`; the `@internal` `resetLoggedUnknownModels()` is removed.
- Verified on Octane (RoadRunner, 2 workers, 1,000 requests): no trace context, execution context, tracing toggle, pricing memo or exporter segment crosses requests.

### Llm::fake() and router prompt files

- New: `Shahzad\AgenticGraph\Testing\Llm::fake($responses)` returns an `LlmFake` bound to the app for the test. Built over Prism's fake (the real executor runs, so tracing, `TokensUsed`, retries and streaming behave as in production). Queue, one entry per provider request: a string (reply text; decoded as JSON for a structured request), an array (structured data), a `Throwable` (thrown: a failed attempt) or a Prism response fake (tool calls, steps, usage). An empty queue answers every request with an empty reply; a queue that runs out throws.
- New: each `run()` of a node's `llm()` and each `PrismRouter` routing call is recorded once: `node`, `vars` (resolved, the `context()` variable included), `template_vars`, `history` (the literal `user()`, `assistant()`, `history()` messages), plus `explain()`'s `messages`, `settings` (with sources), `stream`, `cache`, `tools`, `schema`, `prompt`, and `context` with its items. Assertions: `assertPrompted(fn)`, `assertNotInterpolated($var)`, `assertSettings([...])` (value, or value and source), `assertSentCount($n)`, `assertNothingSent()`; `recorded()` and `prism()` for the rest.
- New: `ChatPromptTemplate::getInterpolatedVariables()` (`getVariables()` without messages placeholders).
- New: `PrismRouter` takes `prompt:` (a prompt file from `Prompts::get()` or any `ChatPromptTemplate`) for its routing prompt, formatted with the new `routingVars($state)` hook (`routes`, `state`; override to add). The loop context is appended to the last message. The llm span carries `prompt.name`, `prompt.version` and `prompt.hash`, as for nodes. Without `prompt:` the router sends `buildPrompt()` as before. A routing call made with a template sends messages, so its trace prompt text gains the `[user] ` prefix and its router cache key changes.
- Internal: `LlmExecutor::run()` takes an optional second argument with the builder's detail for the fake (`@internal` class).

### Prompt files
- New: prompt files. Markdown with front matter under `config('agenticgraph.prompts.path')` (new key, default `resource_path('prompts')`, env `AGENTICGRAPH_PROMPTS_PATH`, read with that default in code). Front matter: `name` (required, the dotted path: `support/reply.md` is `support.reply`), `version` (required), `description` (optional); no model or settings, those stay on the node. Body: `## system`, `## user`, `## assistant` sections and `## messages: <var>` placeholders; `{var}` variables and `{{` `}}` literal braces (the interpolator's rules).
- New: `Prompts::get($name)` returns a `PromptFile` (a `ChatPromptTemplate` with `name`, `version`, `hash`, `description`, `meta()`), a fresh copy each call, parsed once per process per content hash. `hash` is the first 12 hex of the sha256 of the raw file. Errors throw `TemplateException` naming the file: missing or mismatched `name`, missing `version`, a `model` or settings key, an unknown key, nested YAML, an unknown heading, text before the first heading, an empty section.
- New: `php artisan agenticgraph:prompts` lists name, version and hash of every file and exits 1 when any is invalid (for CI). Publishable example: `vendor:publish --tag=agenticgraph-prompts`.
- New: `explain()['prompt']` (`name`, `version`, `hash`; `null` without a prompt file).
- Trace wire, additive (v1.1): an llm span of a call made with `chat(Prompts::get(...))` carries `prompt.name`, `prompt.version` and `prompt.hash` in `attributes` (also on a cache hit). The schema's llm `attributes` now require each group whole (`dependentRequired`) instead of always requiring the context pair. Registry, wire doc, JSON Schema, OTLP note (strings, passed through), and the freeze-corpus case. `llm_calls` rows are unchanged. `TokensUsed` gains `promptFile`.

### `context()`
- New: `context($items, as: 'context', format: ?PromptTemplate, separator: "\n\n")` on `LlmRequest`. `$items` is a list of plain items (`id` string or int, `content`, optional `metadata` and `score`) or any object with `toContextItems(): array` returning that list (structural, no shared interface; core does not import the vector package). Each item renders as its content, or through the `PromptTemplate` with its metadata plus `id`, `content` and `score` (a missing key throws `TemplateException`). The joined text reaches the prompt as the variable `as`, never interpolated again; the same name in `vars()` throws `LogicException`.
- New: `LlmResponse::sources()` (the items used: `id`, `metadata`, `score`, in prompt order; also on a cache hit) and `sources` in `toArray()` (`[]` without `context()`).
- New: `explain()['context']` (`var`, `count`, the formatted `text`; `null` without `context()`).
- Trace wire, additive (v1.1): an llm span whose request used `context()` carries `attributes` with `context.count` and `context.ids` (the first 20 ids, as strings). Registry, wire doc, JSON Schema, OTLP note (the `attributes` object passes through; the id list as its JSON string) and a freeze-corpus case. `TokensUsed` gains `contextCount` and `contextIds`.

### Multi-step streaming
- Tool runs with `maxSteps > 1` and MCP now stream every step when a sink is bound (they ran non-streamed before). `explain()['stream']` follows.
- **BREAKING (stream wire):** Prism's StreamStart and StreamEnd map to new `stream_start` and `stream_end` events (they were `step_start` and `step_finish`, so one step gave two of each). `stream_end` carries the request's `finish_reason` and cumulative `usage` (plus `citations`, `additional_content`); `stream_start` carries `model`, `provider`, `metadata`. `step_start` and `step_finish` are now one pair per step; `step_finish` gains that step's `finish_reason` and `usage`. Every streamed graph's sequence changes.
- **Behavior change:** a streamed tool run's `LlmResponse::$text` is the final step's text, as non-streamed (it concatenated every step). New `LlmResponse::$steps` (`list<LlmStep>`: text, tool calls, results, usage, finish reason per step) for tool and MCP runs, and `steps` in `toArray()`.
- **Behavior change:** `NodeStreamAccumulator` sets `text` to the final step's text as a string (it was an array when a node streamed several message ids).
- A streamed node attempt that already emitted content is not retried at node level (unchanged rule), so a multi-step run that fails in a later step fails the node.
- Documented: a structured retry after a streamed attempt runs without streaming (unchanged since the structured retry below); its reply is in the result, not on the wire.

### Structured output: retry, `as()`, `structuredMode()`
- New: `structuredMode(string|StructuredMode)` on `LlmRequest` (`auto`, `json`, `structured`). Only the OpenAI provider reads it; any other provider throws a `LogicException` before the call. Shown in `explain()['settings']['structured_mode']`; in the cache key only when set.
- New: structured retry. A reply that does not decode, or does not fit `as()`, is sent again with the failed reply and the error appended. Config `agenticgraph.prism.structured.retries` (`AGENTICGRAPH_PRISM_STRUCTURED_RETRIES`, default `1`, read with that default in code; `0` throws at once). `PrismRouter` pins 0 (it has its own retry). **Behavior change:** the silent streamed-to-non-streamed fallback is gone; the streamed reply is attempt 1 and a failure retries or throws.
- New: `as(Dto::class)`, `data()`, `LlmResponse::$data`, `#[ListOf]`, `StructuredHydrationException`. Reflection-only hydration (constructor or public properties; scalars, nullable and union types, backed enums, date-times, nested classes, typed lists), strict, errors name the path and expected type.
- Fixed: `LlmResponse::$usage` of a structured call now sums every attempt (the streamed attempt's usage was lost after a fallback). New `LlmResponse::$attempts` and `attempts` in `toArray()`. When Prism discards the usage of a failed non-streamed attempt, `usage` is `null` with a warning (never zero) and `TokensUsed` is dispatched only for attempts whose usage is known.

The trace wire (`agenticgraph.trace.v1.1`, the v1 contract plus the v1.1 amendment) is frozen; later changes are additive, locked by `TraceWireContractFreezeTest`. The Pulse exporter's payload is an internal, versioned format between AgenticGraph and AgenticPulse. To send traces to your own backend, use OTLP.

### Trace JSON Schema and wire fixes

- New: a JSON Schema for the trace wire (segment envelope and merged trace), kept with AgenticPulse and checked against real captures there. `TraceWireContractFreezeTest` keeps its key registry inline.
- Pricing: hand-set prices live in `config/model_pricing_overrides.php` (published with the other config). `ModelPricingCalculator` merges it over `config/model_pricing.php` (an override replaces the whole entry) and `agenticgraph:pricing:sync` never writes it; the sync reports the ids an override covers. `openai/gpt-6-luna` moved there from the sync table (OpenAI Standard tier, short context up to 272K input: input 0.10, cached input 0.01, cache write 0.125, output 0.50 per 1M tokens; the cache write rate is marked TODO for a check against the official page).
- Fixed: a wrapped tool's span now carries `tool.output_size` (the size of the result) at every `tool_results` depth; the key was documented but only a provider-executed tool call could carry it, with a null size. `tool.result_size` (summary depth) is unchanged.
- Fixed: `llm_calls[].duration_ms` is always a number (0.0 when the event has no measured duration); it could be null.
- Trace wire, additive: in the merged trace, a `segments` entry of a segment that ended interrupted gains `interrupt` (`{reason, at}`). Before, the merge removed `trace.interrupt_reason` and `trace.interrupted_at` once the run was resumed and nothing recorded that, why or when a segment was interrupted except the interrupt span. Wire doc clarified: `state_diff` and `loop_context` are on successful node attempts, `model` may be null, `active_cycles` is a list of `{cycle, count}`, `tool.request_id` and `gen_ai.tool.call.id` exist only for provider-executed tool calls, and node spans never carry `links`.

### Prism 0.102, PHP 8.3 and usage semantics v2

- **BREAKING:** requires `prism-php/prism` `^0.102.0` (was `^0.100.1`), and therefore `laravel/framework` `^12.61.1 || ^13.12.0`. **Laravel 11 is no longer supported** (Prism 0.101 dropped it; the CI matrix, `composer.json`, README and compatibility table follow). `orchestra/testbench` `^10.0|^11.0`.
- **BREAKING:** requires PHP 8.3+ (`php` `^8.3`, was `^8.2`). Laravel 13 needs 8.3, and CI tests 8.3 and 8.4 only.
- **BREAKING (numbers): usage semantics v2.** Prism 0.102 gives `Usage` one meaning on every provider, and the package reports it as is: `prompt_tokens` is the uncached input; cached input is in `cache_read_tokens` / `cache_write_tokens` and never inside `prompt_tokens`; `completion_tokens` includes reasoning, `thought_tokens` is its reasoning part. The numbers change wherever a provider used to deviate: Gemini `completion_tokens` now includes thinking (higher) and `prompt_tokens` excludes implicit cache hits; OpenAI streams, OpenRouter, DeepSeek, Mistral, Groq, Z, xAI and Ollama 0.33.3+ `prompt_tokens` exclude cached input (lower); multi-step streams on Gemini, Groq and OpenRouter are now the sum of every step, not the last; `thought_tokens` is newly reported by DeepSeek and OpenRouter. Anyone storing usage for billing or analytics sees a step change.
- New: nullable `cacheReadTokens` and `cacheWriteTokens` on `TokensUsed` (and `TokensUsed::fromLlmCall()`), `cache_read_tokens` / `cache_write_tokens` in `LlmResponse::toArray()['usage']`, and on the `llm` span. Null: the provider does not report it; 0: it reported zero. Trace wire, additive (v1.1, "usage semantics v2"): the two optional span keys; `total_tokens` stays prompt + completion and so excludes cached input. OTLP: `agenticgraph.cache_read_tokens`, `agenticgraph.cache_write_tokens`, `gen_ai.usage.cache_read.input_tokens` and `gen_ai.usage.cache_write.input_tokens` (names verified against the pinned semantic-conventions-genai commit); `gen_ai.usage.input_tokens` is now `prompt + cache read + cache write`, which is what OTel defines (it was the raw prompt count before).
- Pricing: `ModelPricingCalculator::calculate()` takes the cache buckets. A `config/model_pricing.php` entry may set `cache_read` and `cache_write` (USD per 1M tokens); cached input costs that rate, else the entry's `input` rate (what it cost when providers counted it inside the prompt). `agenticgraph:pricing:sync` does not write the two keys yet; set them by hand.
- Finish reasons: `LlmResponse::$finishReason` (and `finish_reason` in `toArray()`) can now be `length` for a **streamed** call cut by `$maxTokens`, with the real usage (a truncated OpenAI stream used to report `stop` and 0 tokens, a known upstream issue). New values `refusal` and `pause` exist. A non-streamed OpenAI call cut by the limit still throws `max tokens exceeded`. A stream that ends in an in-stream error now throws a `PrismException` after its events were sent (Prism 0.102 ends it as a normal stream with finish reason `error`); the call's tokens are not tracked, as for a thrown non-streamed call.
- Fixed: `PrismNode::audioToText()` threw a `TypeError` with token tracking on for duration-billed transcriptions (OpenAI whisper-1, Groq), where Prism 0.102 returns `null` usage. No `TokensUsed` is dispatched for them.
- Reasoning: Anthropic `off` now sends `thinking.type = disabled`. Prism 0.102 ignores the old `thinking.enabled = false` (it sends nothing), and Claude 5 models think by default, so `off` did not turn thinking off. Level and budget are unchanged. Every row of the reasoning mapping was re-checked against the 0.102 handlers (OpenAI, Gemini, OpenRouter, Ollama, DeepSeek unchanged); `ReasoningTest` now asserts the request body per provider. Known gap, same in 0.100: Ollama structured requests do not send `think`.
- Docs: unknown OpenAI model names (custom names on a gateway) get `json_schema` since Prism 0.102; Prism's per-request `StructuredMode::Json` and how a node reaches it are in the nodes chapter (`GatewayStructuredModeTest`).
- Removed from the known issues: "a truncated OpenAI stream reports zero usage".

### Reasoning level and thought tokens

- New: `protected ?string $reasoning` (`off`, `low`, `medium`, `high`) and `protected ?int $reasoningBudget` on `PrismNode`, and `->reasoning(?string $level = null, ?int $budget = null)` on `LlmRequest`. Precedence call, then node; **no config layer** (a global level would break nodes on providers that cannot apply it). Null sends nothing.
- The package maps the setting to the option Prism reads for the provider: OpenAI `reasoning.effort`; Gemini `thinkingLevel`, `thinkingBudget` (off is budget 0); Anthropic `thinking` (the app's budget from the new `agenticgraph.prism.reasoning.budgets.{low,medium,high}`, env `AGENTICGRAPH_PRISM_REASONING_BUDGET_*`, no package defaults); OpenRouter `reasoning`; Ollama `thinking` (the level is reduced to on). Never a silent no-op: the DeepSeek provider, a provider with no mapping, a budget where none exists, a level without its Anthropic budget and Anthropic thinking with a forced tool choice throw `ReasoningException` before the call; a vendor 4xx (not a rate limit) is rethrown as `ReasoningException` with the level and the option sent.
- OpenAI `off`: the value that means no reasoning is model-specific (`none`, `minimal`, `off`), so it comes from the app: `agenticgraph.prism.reasoning.off.openai` (env `AGENTICGRAPH_PRISM_REASONING_OFF_OPENAI`), no package default; `off` without it throws `ReasoningException` naming the key. A node whose model needs another value uses raw `providerOptions(['reasoning' => ['effort' => '...']])`.
- New event `ReasoningNotDisabled`: level `off` that still reports thought tokens logs a warning, fires the event and adds to `LlmResponse::$warnings`. Not an exception.
- The derived option is merged recursively into the node's and the call's provider options (other keys under the same option survive; the reasoning setting wins on the same key). `explain()` has `settings.reasoning` (level, budget, options, note, source) and the exact options in `settings.provider_options`. The cache key includes them.
- `thoughtTokens` (nullable: Anthropic, DeepSeek and Ollama do not report it) on `TokensUsed`, `LlmResponse::toArray()['usage']['thought_tokens']` (new key) and the llm span. `LlmResponse::toArray()` also gains `warnings`.
- Trace wire, additive (v1.1 note): the `llm` span has an optional `thought_tokens` (integer), absent when unknown. The OTLP exporter maps it to `agenticgraph.thought_tokens`.
- Docs: on reasoning models `$maxTokens` includes the reasoning tokens (nodes chapter, core skill). (The known issue that a truncated OpenAI stream reports zero usage is fixed by Prism 0.102, see "Prism 0.102, PHP 8.3 and usage semantics v2".)
- Not done: core `PrismNode::embeddings()` has no `url` either (later list).

### Release metadata

- README: an "Upgrading from 0.1.0" section and a compatibility table (core 0.2.x, vector 0.1.x, stream client 0.1.x; PHP 8.3+, Laravel 12.61.1+ or 13.12+, Prism 0.102). `composer.json` gains keywords and `support` (issues, source, docs). `.claude/settings.local.json` is no longer tracked. Dev lock updated (`composer audit` clean; guzzle 8).

### Upgrade migrations (from 0.1.0)

- The four upgrade migrations are now published with the others, in this order: `zzzz_add_checkpoint_node_count_to_agenticgraph_executions_table`, `zzzz_add_execution_options_to_agenticgraph_executions_table`, `zzzz_drop_checkpoint_tree_columns_from_agenticgraph_checkpoints_table` and the new `zzzz_add_stream_columns_to_agenticgraph_executions_table` (adds `stream_id` with its index and `interrupted_at`, drops 0.1.0's `(id, status)` index). Each checks the schema first, so on a fresh install they change nothing, and every `down()` skips a column that is already gone.
- Upgrading an app that published the 0.1.0 migrations: `php artisan vendor:publish --tag=agenticgraph-migrations`, then `php artisan migrate`. Files the app already has keep their names; the new tables (`stream_events`, `agenticgraph_interrupts`) and the four upgrades are added. `UpgradeMigrationsTest` checks that the result matches a fresh install.

### BREAKING (one LLM request builder: `$this->llm()`)
- `PrismNode::llm()` returns an immutable `Shahzad\AgenticGraph\Llm\LlmRequest`. It replaces `prompt()`, `promptTemplate()`, `chatPrompt()`, `promptMessages()`, `structured()`, `tools()`, `agent()` and `withMcp()`, which are removed, with `withModel()`, `buildStreamingRequest()`, `trackTokensFromResponse()`, `interpolate()`, `shouldCache()`, `getCacheKey()`, `dispatchCachedLlmCall()` and the node-level `withCache()` / `withoutCache()` (caching is now chosen per request; the `$cacheEnabled` and `$cacheTtl` properties stay as node defaults).
- Removed classes: `Tools\ToolRun`, `Tools\AgentResponse`, `Mcp\McpRequest`, `Mcp\McpResponse`. `run()` returns `Llm\LlmResponse`: `text`, `structured`, `toolCalls` and `toolResults` of every step (`ToolRunResult`, unchanged), `usage`, `finishReason`, `cached`. It is `JsonSerializable`, so it can be stored in state. Its array keys: `text`, `structured`, `tool_calls`, `tool_results`, `usage`, `finish_reason`, `cached`.
- Templates interpolate, inline content never does: `prompt()` (a string is wrapped in a new `PromptTemplate`) and `chat()` are formatted with `vars()`; `system()`, `user()`, `assistant()` and `history()` reach the model unchanged. A prompt string with literal braces now needs `{{` and `}}` (the old `structured()` sent its string as is).
- Two templates in one request throw, and so do `user()`, `assistant()` or `history()` with `prompt()` (Prism refuses a prompt and messages together; use `chat()`). `system()` with `prompt()` is sent as the system prompt.
- Tools: `tools()`, `mcp($server, ['tools' => ...])`, `toolChoice()`, `maxSteps()`. Defaults for every tool request: one step and Prism's tool choice. `agent()` defaulted to `ToolChoice::Any` and 5 steps, `withMcp()` to `ToolChoice::Any`: pass them explicitly.
- Settings: config, then node properties (`$model`, `$maxTokens`, `$providerOptions`, `$trackTokens`), then `model()` / `maxTokens()` on the request. `providerOptions()` is merged over the node's, the request winning per key. `explain()` returns the resolved request without a model call: messages, every setting with its source, the stream decision, the cache decision and key, tools with choice and steps.
- Caching: structured replies may now be cached (`withCache()`); requests with tools or MCP never are. The media check is `MessageSerializer::hasMedia` on every path, so a prompt with only `Text` attachments can now be cached. The cache key payload now also covers the system prompt and the schema, so every existing entry misses once after upgrading.
- Streaming is unchanged: text and chat stream when a sink is bound; a schema streams only when it is a Prism `Schema` and the request uses `prompt()`; single-step tool calls stream; multi-step runs and MCP do not. The streamed schema prompt is no longer interpolated a second time.
- Named changes: timeouts (`agenticgraph.api.timeout`, `api.connect_timeout`) now apply to structured and MCP requests too. MCP `TokensUsed` (`call_type` `mcp`) now carries the prompt and the response. Tool wrapping is reset per request (a node that once wrapped tools stopped tracing unwrapped tool calls for the rest of its life).
- `PrismRouter` runs through the same internal executor; its retries, default-route fallback, 100-token cap, cache and `TokensUsed` are unchanged.

| Before | After |
|---|---|
| `$this->prompt('Hi {name}', ['name' => $n])` | `$this->llm()->prompt('Hi {name}')->vars(['name' => $n])->text()` |
| `$this->promptTemplate($t, $vars)` | `$this->llm()->prompt($t)->vars($vars)->text()` |
| `$this->withoutCache()->chatPrompt($t, $vars)` | `$this->llm()->withoutCache()->chat($t)->vars($vars)->text()` |
| `$this->promptMessages($messages)` | `$this->llm()->history($messages)->text()` |
| `$this->prompt($p, [], [$image])` | `$this->llm()->prompt($p)->attach([$image])->text()` |
| `$this->structured($prompt, $schema)` | `$this->llm()->prompt($prompt)->schema($schema)->structured()` |
| `$this->tools($prompt, $tools)` | `$this->llm()->prompt($prompt)->tools($tools)->run()` |
| `$this->agent($p, $tools, ['systemPrompt' => $s, 'toolChoice' => 'lookup', 'maxSteps' => 5])` | `$this->llm()->system($s)->prompt($p)->tools($tools)->toolChoice('lookup')->maxSteps(5)->run()` |
| `$this->withMcp('docs', ['toolChoice' => ToolChoice::Any, 'maxSteps' => 5])->withPrompt($p)->asMcp()` | `$this->llm()->prompt($p)->mcp('docs')->toolChoice(ToolChoice::Any)->maxSteps(5)->run()` |
| `$this->withModel('openai:gpt-4o')` | `protected ?string $model = 'openai:gpt-4o';` (or `->model()` on one request) |

### Changed (trace wire v1.1, pre-release amendment)
- Queued work that outlives its caller: `TraceContext` takes optional `links` (`[{trace_id, span_id}]`, the span that queued the work). Spans that `Tracer` exports directly under the root of such a context carry `links`, a new optional span key; the OTLP exporter sends it as OTLP span links. The package's own jobs are unchanged.
- `schema_version` is `agenticgraph.trace.v1.1` (was `agenticgraph.trace.v1`), so a receiver can detect `links` and the merge rules below. Nothing else in the envelope changed; a receiver should accept both strings.
- `TraceSegmentMerger`: `trace.ended_at` is the latest segment end (it was the final segment's end), so `duration_ms` covers every segment and `active_ms` is never above it. The root graph span keeps the graph's end; spans with `links` may end after it.

### BREAKING (SSE endpoint: control events, held-open waits, firstSeq)
- `StreamStoreInterface` has a new method `firstSeq(string $streamId): ?int` (oldest stored seq, `null` when empty). Implemented on the Redis and database stores. Custom stores must add it.
- SSE control events (`stream_heartbeat`, new `stream_reconnect`, `stream_replay_unavailable`) have no `id:` line and no `seq`, and are never stored. Their body is `{"control": "<name>", ...}`, not the 9-field wire body. `stream_replay_unavailable` used to carry the next real seq as its id, so a later reconnect with that cursor skipped a real event.
- `stream_replay_unavailable` is sent only when the cursor is really gone (`cursor + 1 < firstSeq`, or an empty stream with `cursor > 0`), once, and then the response closes. It used to be sent after one quiet second on any reconnect with a cursor, including a normal approval wait.
- An `interrupted` `execution_terminal` keeps the response open (heartbeats), so the resumed segment arrives on the same connection. `completed` and `failed` still close it.
- At `streaming.sse.max_duration_seconds` the response ends with control `reconnect` (`{"control":"reconnect","after_ms":<retry_ms>}`). It used to end silently.
- Every SSE response starts with `retry: <ms>`. New config `streaming.sse.retry_ms` (`AGENTICGRAPH_STREAM_SSE_RETRY_MS`, default 2000). `max_duration_seconds` accepts fractions.
- Wire contract v1.1 amendment: [WIRE-CONTRACT.md](https://github.com/shahzad-io/agenticgraph/blob/main/docs/streaming/WIRE-CONTRACT.md) (SSE connection lifecycle, control events). Use `@agenticgraph/stream-client` from the same release.

### BREAKING (tools() returns a ToolRun; agent() returns an AgentResponse)
- `PrismNode::tools()` returns `Shahzad\AgenticGraph\Tools\ToolRun` instead of `ToolCall[]`: `calls` (`ToolCall[]`), `results` (per call: `toolCallId`, `toolName`, `arguments`, `result`, `error`) and `text`. It stays single-step: the results are not sent back to the model. `ToolRun` is `Countable` (calls) and `JsonSerializable`, so it can be stored in state.
- `error` is set when a handler threw, returned a `ToolError`, or returned an `ERROR:` / `Tool execution error:` string. Fixed on the way: a tool that threw used to reach the model as a misleading "Parameter validation error"; it now gets Prism's own error message.
- `PrismNode::agent()` returns `Shahzad\AgenticGraph\Tools\AgentResponse`, a Prism `TextResponse` with `results`: the tool results of every step (Prism's `toolResults` has only the last step's). Code that reads `->text` is unchanged.
- Streaming is unchanged: tool calls and results still stream as events.

Before:

```php
$calls = $this->tools('Look up order 42', $tools);       // array<int, ToolCall>
return $state->set('tool_calls', $calls);                 // the handlers ran, their results were lost
```

After:

```php
$run = $this->tools('Look up order 42', $tools);          // ToolRun
$run->results[0]->result;                                 // what the handler returned
$run->results[0]->error;                                  // null, or why it failed
return $state->set('lookup', $run);                       // stored as plain data
```

### BREAKING (one caching rule, off by default)
- `agenticgraph.prism.cache_enabled` (`AGENTICGRAPH_PRISM_CACHE`) defaults to `false`. It was `true`. Apps with a published `config/agenticgraph.php` keep their old default until they change it there.
- One rule for `prompt`, `promptTemplate`, `chatPrompt` and `promptMessages`: a reply is cached only when the call is not streamed, has no media, and caching is on for the node. `withCache($minutes)` / `withoutCache()` on the node win over the config. `withCache()` now turns caching on even when the config is off. `promptMessages()` now caches under the same rule.
- The cache key covers the model, max tokens, provider options and the full message list (role, content, tool calls, tool results). `getCacheKey()` takes the message list instead of a prompt string. Existing cache entries are not reused.
- The prompt in `TokensUsed` for `chatPrompt` and `promptMessages` keeps every role and the tool calls and results. It used to label every message as user and drop tool messages.
- `PrismRouter` reads the same key with the same default.

### Interrupt expiry
- New command `agenticgraph:interrupt:expire` (schedule it). It moves pending interrupts past `expires_at` to `expired` with a conditional update. The execution becomes `failed`; the stream gets `graph_failed` with `reason: "interrupt_expired"`, then a failed `execution_terminal`, then the broadcast `stream_close`. `GraphExecutionFailed` and the new `InterruptExpired` event fire once per interrupt.
- `graph_failed` has an optional `reason` field (wire contract, additive).
- Every resume path refuses an expired interrupt: `AgenticGraph::resume()` returns `ResumeResult` with the new `expired` flag and dispatches nothing; `ExecutionHandle::resume()` (sync and async), a stream resume and `agenticgraph:checkpoint:resume` throw `InterruptExpiredException`. The interrupt controller's resolve response has `expired`.
- `InterruptRecord::markResolving()` is conditional (`where status = pending`) and returns `bool` instead of the record, so a resume cannot overwrite an expired interrupt.

### Tracing can be turned on at runtime
- The tracing listeners and the HTTP and database instrumentation are always registered. Each handler reads `agenticgraph.tracing.enabled` when its event fires, so `config(['agenticgraph.tracing.enabled' => true])` at runtime produces spans. The Pulse and OTLP exporters read it per call too.
- With tracing off, each handler costs one config read. The database listener runs on every query: about 2 microseconds per query in the package speed test (in-memory SQLite).
- Booting with tracing off does not build a tracer exporter.

### BREAKING (stream wire contract v1.1)
- `final_state` is now `null` by default in `execution_terminal`, broadcast `stream_close.meta` and `stream_replay_unavailable`. Apps that read it on the client must set `AGENTICGRAPH_STREAM_EXPOSE_FINAL_STATE=true`, ideally with an allowlist in `AGENTICGRAPH_STREAM_FINAL_STATE_KEYS`.
- When exposed, `final_state` is the plain state object (top-level keys), not the internal `__agenticgraph_state` wrapper.
- `execution_terminal` is the last event of a run, and `stream_close` the last broadcast. On a failure, `graph_failed` comes before the terminal. Each run closes once.

### BREAKING (Prism 0.100, no default model)
- Requires `prism-php/prism` `^0.100.1`. Prism 0.100 sends Anthropic structured output in native mode, which needs Claude Sonnet 4.5 or newer. For older Anthropic models set `$providerOptions = ['use_tool_calling' => true]` on the node.
- `agenticgraph.prism.model` (`AGENTICGRAPH_PRISM_MODEL`) has no default. It was `anthropic:claude-sonnet-4`. With no node `$model` and no config, the first Prism call throws `MissingModelException`. PrismRouter rethrows it instead of retrying or using its default route.
- A model without a provider prefix (`gpt-4o`) throws `MissingModelException`. It used to mean `openai:gpt-4o`.
- `PrismNode::$providerOptions` is sent with every text, structured and streamed call.

### Pre-release fixes
- Logs no longer carry state values, model output or stack traces. PrismRouter logs state keys, router class, exception class, message, file and line. Parallel merge collisions log keys and branch names only.
- `ChatPromptTemplate::messagesPlaceholder($variable)` inserts a list of messages (arrays or Prism message objects) without interpolation. Use it for chat history and any other untrusted text.
- `ExecuteGraphJob` failures (for example a graph not registered on the worker) mark the execution failed, append `graph_failed` and a failed `execution_terminal`, send the broadcast `stream_close`, and fire `GraphExecutionFailed` once.
- `ResumeExecutionJob` failures do the same, and the interrupt is resolved with resolution `failed` (error in its metadata), so a later resume returns "already resolved".
- `PrismNode::$maxTokens` sets max output tokens for every helper (prompt, promptTemplate, chatPrompt, promptMessages, structured, tools, agent, withMcp). Falls back to `agenticgraph.prism.max_tokens`. PrismRouter keeps its own 100.
- Provider note (Prism OpenAI handler): a reply that hits the limit throws in non-streamed and `structured()` calls, and a streamed reply stops at the limit with usage reported as 0. Treat `$maxTokens` as a ceiling and ask for short replies in the prompt.
- Stream route middleware is configurable through `AGENTICGRAPH_STREAMING_MIDDLEWARE`.
- `@method` docblock tags that described real methods (mostly protected PrismNode helpers) are removed. PrismNode lists its helpers in prose.
- The Redis stream store keeps `null` for `node_name`, `span_id`, `parent_span_id` and `node_seq`. Older entries that stored `""` read back as `null`.

### New config keys
Apps with a published `config/agenticgraph.php` keep their old copy, because the package merges config shallowly. Add these keys or republish the config. The package uses the defaults below when a key is missing.

| Key | Env | Default |
|---|---|---|
| `streaming.final_state.expose` | `AGENTICGRAPH_STREAM_EXPOSE_FINAL_STATE` | `false` |
| `streaming.final_state.keys` | `AGENTICGRAPH_STREAM_FINAL_STATE_KEYS` (comma-separated) | `null` (all keys when exposed) |
| `streaming.routes.middleware` | `AGENTICGRAPH_STREAMING_MIDDLEWARE` (comma-separated; `throttle:5,1` stays together) | `['api']` |
| `execution.tries` | `AGENTICGRAPH_EXECUTION_TRIES` | `1` |
| `execution.timeout` | `AGENTICGRAPH_EXECUTION_TIMEOUT` | `0` (worker default) |
| `execution.resume_tries` | `AGENTICGRAPH_RESUME_TRIES` | `1` |
| `execution.resume_timeout` | `AGENTICGRAPH_RESUME_TIMEOUT` | `0` (worker default) |
| `prism.max_tokens` | `AGENTICGRAPH_PRISM_MAX_TOKENS` | `null` (no limit sent) |

### BREAKING for exporter implementers
- `TracerExporter` signatures changed. `completeTrace`, `failTrace` and `interruptTrace` take an optional `?\DateTimeInterface $at`; `resumeTrace` takes `?string $graphName`, `string $kind` and `?\DateTimeInterface $at`. Third-party exporters must update.
- `PulseExporter::sendIngestPayload()` is renamed to `send()`.
- `BufferedTraceExporter::whileCapturing()` is public. Package bookkeeping runs inside it so its queries and requests are not captured. A custom exporter that implements `TracerExporter` directly, without extending `BufferedTraceExporter`, gets no such suppression.
- Events: `NodeExecutionCompleted` describes the successful attempt. `NodeExecutionFailed::$spanId` changes per attempt, and the event takes optional trailing `nodeType` and `attemptOf` arguments.

### BREAKING (wire)
- Node spans carry `attempt` (1, 2, ...) and `attempt_of` (the id of attempt 1) instead of `retry_count`. One span per attempt.
- Every envelope carries a `segment` block (`id`, `kind`, `final`, `started_at`, `ended_at`) and only that segment's spans and LLM calls. A resumed trace is no longer resent whole: receivers merge segments by trace id. Non-final segments have `trace.status = running` (or `interrupted`).
- Span attributes live in one `attributes` object with dotted names. Top level keeps only the common keys, the llm fields (`model`, `prompt_tokens`, `completion_tokens`, `total_tokens`, `cost_usd`, `cached`, `streamed`) and `state_diff`, `loop_context`, `error`, `state_snapshot`. Moves: route `route.*`; tool `tool_type`, `tool_provider`, `tool_request_id`, `input_size`, `output_size`, `tool_call_id` become `tool.type`, `tool.provider`, `tool.request_id`, `tool.input_size`, `tool.output_size`, `gen_ai.tool.call.id`; interrupt `interrupt_reason`, `checkpoint_id` become `interrupt.reason`, `checkpoint.id`; resume `checkpoint_id` becomes `checkpoint.id`.
- `status_code` is `OK`, `ERROR` or `PAUSED` on every span. On http spans the HTTP status moves to `attributes["http.response.status_code"]` (int).
- db spans: `db.connection` becomes `laravel.db.connection`, `db.type` becomes `laravel.db.statement_type`, `db.bindings_count` becomes `laravel.db.bindings_count`, `db.sql` becomes `laravel.db.query_summary` (summary) or `db.query.text` (full). New `db.operation.name` (the SQL verb, single statements only).
- http spans: the `http` object is gone. `http.request.method`, `laravel.http.url_summary` or `url.full`, and `laravel.http.response_size` are in `attributes`. A connection failure or fallback span with an exception carries `error`.
- `trace.resource` and the OTLP resource use `deployment.environment.name` instead of the deprecated `deployment.environment`.
- A payload over 10 MB keeps its real `trace.status`. The reason moves to `_meta.error_reason = payload_too_large`, with dropped counts in `_meta.dropped`.
- `trace.status` gains `running` for non-final segments.

### Behavior
- Every wire timestamp is UTC with microseconds (`Y-m-d\TH:i:s.u+00:00`), whatever the app timezone.
- The `auto` exporter is `null` when `app()->runningUnitTests()`, so a test suite never sends traces. Otherwise it is `pulse` when `AGENTICPULSE_API_KEY` is set, else `log`. An explicit driver still applies.
- Sampling is deterministic per trace id instead of a random draw per process, so every segment of a trace gets the same decision.
- `executions.duration` holds the measured segment duration for resumed and continued rows.
- The package's own queries and requests (execution rows, checkpoints, interrupts, the stream store, batch dispatch) are never `db` or `http` spans, even with `tracing.capture.db` or `tracing.capture.http` on for the whole run. Only node bodies and tools produce them.
- Traces are sent synchronously by the process that did the work. No queue worker is needed.

### Added
- Tracing: the `TracerExporter` abstraction with `pulse`, `log` and `null` exporters; `Trace::span()` and `TraceContext::tryGet()`; tool-scoped tracing with gated HTTP/DB capture; the capture-depth settings; `config/model_pricing.php` and `agenticgraph:pricing:sync`; the OTLP HTTP/JSON exporter.
- `file` tracing exporter (`AGENTICGRAPH_TRACING_EXPORTER=file`): one JSON file per flushed segment at `{tracing.file.path}/{trace_id}/{Ymd-His-u}.json`. New config key `tracing.file.path` (`AGENTICGRAPH_TRACING_FILE_PATH`).
- `schema_version: agenticgraph.trace.v1` as the first key of every trace envelope, and a machine-readable key registry for it.
- Microsecond timestamps on every span and trace field.
- `trace.active_ms`: the time the trace was actually running, excluding waits between segments.
- The `continued` segment kind, for queue jobs and scopes that work on a trace another process started.
- `TraceSegmentMerger`: reference merge of a trace's segment envelopes (final segment wins, otherwise the later `segment.ended_at`; `active_ms` is the union of segment windows).
- Route spans (`span_type: route`) for conditional edges and joins, with `route.from`, `route.targets`, `route.router` (fully qualified router class, like a node span's `node_type`), `route.kind` (`llm`, `deterministic`, `fan_out`, `join`), `route.from_cache`, `route.fallback`, `route.reason`. The parent is the root graph span; a router's LLM span is a child of its route span. Emitted through the new `RouteDecided` event.
- `streamed` on llm spans and `llm_calls`. New optional `TokensUsed` argument `streamed` (default false).
- `PrismRouter::lastRouteFlags()` returns the `from_cache` and `fallback` flags of the last route.
- The interrupt checkpoint stores the loop context in `metadata['loop']`.
- New optional `ExecuteNodeJob` argument `loopMetadata`. Jobs queued before it run as before.
- Config `tracing.transport.connect_timeout` (`AGENTICGRAPH_TRACING_CONNECT_TIMEOUT`, default 1) and `tracing.transport.timeout` (`AGENTICGRAPH_TRACING_TIMEOUT`, default 3).
- Soft truncation (over 1 MB) adds `_meta.dropped` (`spans`, `llm_calls`), the counts removed from the envelope. `llm_calls` is always 0 there. New optional key.
- `TraceSegmentMerger` keeps each truncated segment's `_meta` in its `segments` entry. The merged `_meta` adds `error_reason` (when a segment has one) and `dropped` (summed over segments).
- The key registry gains a `merged` block (merged keys, `segments` entry keys, merged `_meta` keys), checked by `TraceWireContractFreezeTest` against the merger's own output.

### Changed
- Every node span's parent is the root graph span, including fan-out branches.
- One retry loop for streaming, sync and queue execution.
- `url.full` (capture `http_urls = full`) replaces URL credentials with `REDACTED:REDACTED` and the values of sensitive query parameters (`key`, `api_key`, `apikey`, `token`, `access_token`, `signature`, `sig`, `password`, and the OTel list: `X-Amz-Signature`, `X-Amz-Credential`, `X-Amz-Security-Token`, `X-Goog-Signature`; any case) with `REDACTED`.
- OTLP: attributes are sent under their own names (no `http.` or `db.` prefixing). LLM spans send `gen_ai.operation.name`, `gen_ai.request.model` (without the provider prefix), `gen_ai.provider.name`, `gen_ai.usage.input_tokens`, `gen_ai.usage.output_tokens`, `gen_ai.request.stream`, plus `agenticgraph.total_tokens`, `agenticgraph.cost_usd`, `agenticgraph.cached`; none of the `gen_ai.*` on a cache hit. Tool spans send `gen_ai.operation.name = execute_tool` and `gen_ai.tool.name`. `gen_ai.system`, `gen_ai.prompt`, `gen_ai.completion` and `gen_ai.usage.total_tokens` are no longer sent. Names verified against semantic-conventions v1.44.0 and semantic-conventions-genai `e57c543b`.
- Config keys added since the first published config fall back to code defaults when missing: `storage` (`database`), `middleware.global` (`[]`), `interrupts.handler` (null), `execution.queue_connection` (`redis`), `execution.queue_name` (`agenticgraph`). The OTLP exporter reads `tracing.otlp.endpoint` once, default `http://localhost:4318/v1/traces`.
- Queue node jobs, continue-execution jobs and batch failures each send their own `continued` segment, so a trace run across several workers is no longer lost or cut short.
- The default `tracing.endpoint` is `https://ingest.agenticgraph.dev`.
- Pulse transport: connect timeout 1 s, timeout 3 s, one retry after 500 ms on a connection error or 5xx, never on 4xx (worst case about 6.5 s). A failed send logs a warning with the response `code` or `connection_error`.
- The OTLP exporter shares the segment lifecycle and emits the root graph span only in the final segment.
- A failed batch branch dispatches `GraphExecutionFailed` with the root span id, so the trace ends as `failed`.
- Pulse, file and log exporters send one envelope built by `TracePayloadBuilder`, with the same segment, sampling, interrupt and resume lifecycle (`BufferedTraceExporter`).
- The log exporter logs the full envelope (message `agenticgraph.trace`), one entry per segment: it adds `schema_version`, drops the `exporter` key, applies sampling and size limits, and logs the resumed segment.
- In an HTTP request, every buffered exporter sends after the response (previously only pulse). Console and queue processes send synchronously even with a request bound.
- Instrumentation resolves the exporter lazily.
- Floats keep their zero fraction on the file and pulse wire.
- One instant defines each segment boundary, so a segment's end and the next one's start agree.
- Docs: every merge rule `TraceSegmentMerger` follows is written down.
- Docs: `llm_calls` is the canonical source for cost and tokens; the llm span carries the same values, so a receiver sums one source only. After span truncation an llm call may name a dropped span.
- Docs: a running trace has no root span until an interrupt or the final segment arrives; receivers group its spans by their shared `parent_id`.
- Docs: the zero-fraction note matches the drivers: `pulse` and `file` send `0.0` as `0.0`.

### Fixed
- Timestamps were in whole seconds.
- Sync fan-out branches had no parent in the trace and overlapping times.
- A trace could have several root spans across interrupt, resume, failure and queue. There is now one root span id per trace.
- Retries collapsed into one node span.
- A resumed trace reported a duration of 0.
- A resumed trace lost its first segment in OTLP.
- Traces run across several workers were lost.
- A span that arrived before the trace start (for example from a query in the same process) opened a `continued` segment and the trace start was dropped. The segment now becomes the `initial` segment with the full trace header.
- `duration_ms` is computed from raw microtime values.
- A false "no outgoing edges" warning for `addJoin` sources.
- A false "HTTP response without matching request" warning. The HTTP instrumentation singleton now also cleans up execution state.
- A negative interval is clamped to 0. The `capture.max_length` fallback is 2000.
- Sending a segment forgets only that trace. Clearing every open trace is left to `reset()`, for tests.
- `loop_context.node_visit_count` is correct after a sync or streaming resume, on sync and queued parallel branches, and after a queued join. Exported counts change on these paths. A checkpoint without `metadata['loop']` resumes with fresh counts.
- A streaming `tools()` call in `PrismNode` now dispatches `TokensUsed`, so it produces an llm span.
- A middleware that throws, before or after the node runs, now produces a failed node attempt span.
- `AGENTICGRAPH_TRACING_EXPORTER=null` selects the null exporter. Previously `env()` turned it into PHP null and the factory threw `Unknown agenticgraph tracing exporter []`.
- Span truncation could drop the root graph span when it was not among the first or last 50 spans. The root graph span now always stays; the first 50 and last 50 of the other spans are kept. After truncation a `parent_id` may name a dropped span; receivers attach such spans to the root.

### Removed
- `interrupts.auto_cleanup_days`: nothing read it.
- The duplicate failure events and second `onError` in the streaming path and `ExecuteNodeJob`.
- `db.rows_affected` on db spans (always null).
- The trace buffer in the cache and its config (`tracing.buffer.store`, `tracing.buffer.ttl_seconds`). A resume no longer depends on the cache.
- The unused protected helper `PulseExporter::findSpansOutsideTraceWindow()`.

### Docs
- The wire description named the sampling keys `tracing.sample_rate` and `tracing.always_on_error`. They are `tracing.sampling.rate` and `tracing.sampling.always_on_error`, as in the config. Prose only; the wire format is unchanged.
- Boost: the single `agenticgraph-core` skill is replaced by three skills, `agenticgraph-core`, `agenticgraph-tracing` and `agenticgraph-streaming`, plus a guideline that names them. `BoostSkillsDriftTest` checks that every class, method and env key the skills name exists.

### Known limitation
- With `sampling.always_on_error` and a rate below 1.0, a failed trace that was not sampled is sent with its header and status but no spans. A later version adds them.

## v0.1.0
- Initial public release
- Core agent graph execution engine
- Stable API for graph definition and execution

### Added - 2026-02-01
- Configurable lock timeouts, checkpoint frequency, and max state size.
- Exponential backoff and retry logic for API calls and batch processing.
- Graph structure validation and cycle detection.

### Changed
- Refactored `TraceContext` and `GraphRegistry` to remove static state and improve concurrency.
- Optimized `FileStorage` and database queries to reduce N+1 issues.
- Upgraded PHPStan analysis to Level 6 with comprehensive type hinting.

### Fixed
- "TimeTravel" span timing bugs and race conditions in parallel execution.
- Job serialization issues for queue processing.
