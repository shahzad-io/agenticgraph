# Changelog

## 0.2.0 (unreleased)

Upgrading from 0.1.0? Follow [Upgrading from 0.1.0](README.md#upgrading-from-010) in the README: it covers every step below, in the order to run them.

### Breaking

- **Platform.** Requires PHP 8.3+, Laravel 12.61.1+ or 13.12+, and `prism-php/prism` `^0.102.0`. PHP 8.2, Laravel 10 and 11, and Prism 0.98 are no longer supported. Prism now sends Anthropic structured output in native mode, which needs Claude Sonnet 4.5 or newer; for an older Anthropic model set `$providerOptions = ['use_tool_calling' => true]` on the node.
- **Migrations.** 0.2.0 adds tables and columns. Publish the migrations again and run them (`vendor:publish --tag=agenticgraph-migrations`, then `migrate`). Files you already published keep their names; the new tables and the `zzzz_*` upgrade migrations are added, and each upgrade checks the schema first.
- **One request builder: `$this->llm()`.** The `PrismNode` helpers `prompt()`, `promptTemplate()`, `chatPrompt()`, `structured()`, `tools()`, `withModel()`, `withCache()`, `withoutCache()`, `shouldCache()`, `getCacheKey()` and `interpolate()` are removed. Every model call goes through `$this->llm()`, an immutable request that returns an `LlmResponse` (see the table below). Templates (`prompt()`, `chat()`) are formatted with `vars()`, so a literal brace needs `{{` or `}}`; `system()`, `user()`, `assistant()` and `history()` are sent as written.
- **No default model.** `agenticgraph.prism.model` (`AGENTICGRAPH_PRISM_MODEL`) has no default (it was `anthropic:claude-sonnet-4`), and a model without a provider prefix (`gpt-4o`) is refused. Both throw `MissingModelException` on the first call. Set the env key or `$model` on each node.
- **LLM caching is off by default.** `agenticgraph.prism.cache_enabled` (`AGENTICGRAPH_PRISM_CACHE`) defaults to `false` (it was `true`). Turn it on per request with `->withCache()` or in config. Requests with tools or MCP are never cached. A published `config/agenticgraph.php` keeps the value written in it.
- **Flush the cache.** `CachingMiddleware` now stores state as a plain array and cannot read entries written by 0.1.0, and the LLM and `PrismRouter` cache keys changed. Run `php artisan cache:clear` (or `cache:clear <store>`) after upgrading; each cached reply then misses once.
- **`PrismRouter` without a default route throws.** When the model gives no usable decision (no route key, an unknown route, or every retry failed), it throws `RoutingException` (`router`, `reason`, `reply`) instead of returning an empty route. `RoutingException` extends `RuntimeException`. Set a default route to keep the old tolerance.
- **Token numbers (usage semantics v2).** `prompt_tokens` is the uncached input, cached input is reported in `cache_read_tokens` and `cache_write_tokens`, and `completion_tokens` includes reasoning tokens. Counts in `TokensUsed` change on Gemini, OpenAI streams, OpenRouter, DeepSeek, Mistral, Groq, xAI and Ollama 0.33.3+, and a multi-step stream sums every step. If you store token counts for billing or analytics, expect a step change.

| 0.1.0 | 0.2.0 |
|---|---|
| `$this->prompt('Hi {name}', ['name' => $n])` | `$this->llm()->prompt('Hi {name}')->vars(['name' => $n])->text()` |
| `$this->promptTemplate($t, $vars)` | `$this->llm()->prompt($t)->vars($vars)->text()` |
| `$this->withoutCache()->chatPrompt($t, $vars)` | `$this->llm()->withoutCache()->chat($t)->vars($vars)->text()` |
| `$this->structured($prompt, $schema)` | `$this->llm()->prompt($prompt)->schema($schema)->structured()` |
| `$this->tools($prompt, $tools)` | `$this->llm()->prompt($prompt)->tools($tools)->run()` |
| `$this->withModel('openai:gpt-4o')` | `protected ?string $model = 'openai:gpt-4o';` (or `->model()` on one request) |

### Added

- **Streaming.** `AgenticGraph::stream()` (SSE) and `AgenticGraph::broadcast()` (Laravel broadcasting), or `ExecutionOptions` for full control. Events follow the frozen stream wire contract v1.1: graph and node lifecycle, text deltas, tool calls, thinking, one `step_start` and `step_finish` pair per model step, `stream_start` and `stream_end`, and a final `execution_terminal`. The SSE endpoint replays from a cursor, sends heartbeats, and keeps the connection open through an approval wait. `final_state` is `null` unless you set `AGENTICGRAPH_STREAM_EXPOSE_FINAL_STATE=true` (limit it with `AGENTICGRAPH_STREAM_FINAL_STATE_KEYS`). Use `@agenticgraph/stream-client` 0.1.x on the client.
- **Human in the loop.** A node can pause the run for input (`shouldInterrupt()`, `onResume()`), and `AgenticGraph::resume($executionId, $input)` continues it, sync or queued. Interrupts are stored and expire: schedule `agenticgraph:interrupt:expire`, and an expired interrupt cannot be resumed.
- **The `$this->llm()` builder.** Text, chat, attachments, structured output (`schema()`, or `as(Dto::class)` to hydrate a class, with a retry when the reply does not fit), tools and MCP servers (`tools()`, `mcp()`, `toolChoice()`, `maxSteps()`), `context()` for retrieved documents (it accepts `shahzad/agenticgraph-vector` results), per-request caching, and `explain()` to see the resolved request without calling the model. `LlmResponse` has the text, structured data, tool calls and results, each step, usage, finish reason and the context sources used.
- **Reasoning.** `->reasoning($level, $budget)`, or `$reasoning` and `$reasoningBudget` on a node, mapped to each provider's option (OpenAI, Gemini, Anthropic, OpenRouter, Ollama). A setting the provider cannot apply throws `ReasoningException` before the call. Thought tokens are reported where the provider gives them.
- **Prompt files.** Markdown with front matter in `resources/prompts` (`AGENTICGRAPH_PROMPTS_PATH`), loaded with `Prompts::get('support.reply')`. `php artisan agenticgraph:prompts` lists them and exits 1 when one is invalid, for CI. Each call records the file's name, version and hash.
- **Testing.** `Llm::fake($responses)` runs the real request path over Prism's fake and records every request, with `assertPrompted()`, `assertSettings()`, `assertSentCount()` and `assertNothingSent()`.
- **Tracing** (off by default, `AGENTICGRAPH_TRACING=true`). Spans for graphs, nodes, LLM calls, tools, routes, HTTP, database queries, vector operations and your own `Trace::span()` blocks, with tokens and cost (`config/model_pricing.php`, `agenticgraph:pricing:sync`), across queues, interrupts and resumes. Exporters: `auto`, `pulse` (AgenticPulse), `otlp`, `log`, `file` and `null`. By default a trace carries state key names and value types, not values (`AGENTICGRAPH_CAPTURE_STATE`); prompts, responses and state values are sent only when you turn them on.
- **Interrupt expiry on traces.** An interrupt span carries `interrupt.expires_at` and the interrupting segment `trace.interrupt_expires_at` (ISO 8601, the stored interrupt's `expires_at`); both are absent when the interrupt has no expiry or is not persisted. Additive within trace wire v1.1. `NodeInterrupted` gains `expiresAt`, and `TracerExporter::interruptTrace()` an optional `$expiresAt`.
- **Router settings.** `PrismRouter` takes a `prompt:` template or prompt file, sends `agenticgraph.router.max_tokens` (`AGENTICGRAPH_ROUTER_MAX_TOKENS`, default 1024) and `agenticgraph.router.reasoning` (`AGENTICGRAPH_ROUTER_REASONING`), and logs a warning and dispatches `RouteFellBack` whenever it takes the default route.
- **Events.** `GraphExecutionFailed`, `NodeExecutionFailed`, `NodeInterrupted`, `NodeResumed`, `InterruptPending`, `InterruptExpired`, `CheckpointCreated`, `RouteDecided`, `RouteFellBack`, `LlmCallFailed`, `ReasoningNotDisabled` and `ToolCallCaptured`. `TokensUsed` gains cache, thought-token, streamed and prompt-file fields.
- **Execution settings.** Loops with iteration and cycle limits (`execution.loop`, opt-in), a checkpoint every N nodes (`AGENTICGRAPH_CHECKPOINT_FREQUENCY`), a maximum state size (`AGENTICGRAPH_MAX_STATE_SIZE`), queue job tries and timeouts (`AGENTICGRAPH_EXECUTION_TRIES`, `AGENTICGRAPH_EXECUTION_TIMEOUT`, `AGENTICGRAPH_RESUME_TRIES`, `AGENTICGRAPH_RESUME_TIMEOUT`), `$maxTokens` and `$providerOptions` on nodes (`AGENTICGRAPH_PRISM_MAX_TOKENS`), and image, audio and embedding helpers on `PrismNode`.

### Changed

- `PrismRouter`'s routing call allows 1024 output tokens by default (it was a fixed 100), so reasoning models no longer fall back to the default route for lack of room.
- Package logs no longer carry state values, model output or stack traces.
- Safe under Laravel Octane and long-running queue workers: no graph, trace, execution or pricing state crosses requests (verified on RoadRunner).
- Config keys that 0.1.0 never read are removed: `checkpoint.strategy` (replaced by `checkpoint.frequency`), `checkpoint.compression`, `checkpoint.encryption`, `events.enabled`, `lock.driver`, `lock.prefix`, `lock.strategy`, `lock.retry_interval`, `logging.channel`, `logging.level`, `logging.log_state`, `execution.max_retries`, `execution.retry_delay`, `execution.parallel`, `prism.timeout`, `prism.track_tokens` and `visualization`. `execution.timeout` now reads `AGENTICGRAPH_EXECUTION_TIMEOUT`.

### Fixed

- A false "no outgoing edges" warning for `addJoin()` sources.
- A queued graph job that fails (for example a graph not registered on the worker) marks the execution failed instead of leaving it running.
- `agenticgraph:interrupt:expire` continues the interrupted trace: the failed graph span reuses the stored root span id (one root span in the merged trace), the final segment carries `graph_name` and ends at or after it starts.
- `agenticgraph:interrupt:expire` keeps going when broadcasting fails (for example Reverb is down): the failed `stream_close` broadcast is logged as a warning, here and when a queued job fails. Each interrupt is expired in its own transaction, so one that fails (a throwing `InterruptExpired` listener, for example) is logged and stays pending for the next run while the others expire, and its final trace segment is sent only after the commit, so a rolled-back expiry sends none. `BufferedTraceExporter::sendAfterCommit()` opts a trace into that.

The trace wire (`agenticgraph.trace.v1.1`) and the stream wire contract v1.1 are frozen; later changes are additive. To send traces to your own backend, use OTLP.

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
