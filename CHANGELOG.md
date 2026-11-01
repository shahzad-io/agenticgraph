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
- **Any router's default route now applies.** A router that returns an empty route (`''`, `[]`) or a node the graph does not have takes its `getDefaultRoute()` (a warning, `RouteFellBack` with `empty_route` or `unknown_route`, `route.fallback` on the span), in sync, queued and streamed runs and after a parallel join. With no default route, or one that is not a node, it throws `RoutingException`; a fan-out naming an unknown node always throws. It used to fail with an undefined array key. `RoutingException` and `RouteFellBack` are no longer specific to `PrismRouter`.
- **`getTimeout()` is removed** from `NodeInterface` and `AbstractNode`. Nothing read it, so its 300 seconds never limited a node. A node that defines it keeps working; a call to `parent::getTimeout()` fails. Bound model calls with `->timeout()` on the request, and runs with `AGENTICGRAPH_EXECUTION_TIMEOUT` and `AGENTICGRAPH_RESUME_TIMEOUT`.
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
- **Temperature.** `->temperature($value)` on a request (0 to 2, else `InvalidArgumentException`), `protected ?float $temperature` on a `PrismNode`, and `agenticgraph.prism.temperature` (`AGENTICGRAPH_PRISM_TEMPERATURE`, default `null`: none sent), resolved config, then node, then call; a resolved value outside 0 to 2 throws, naming its source. `PrismRouter` reads `agenticgraph.router.temperature` (`AGENTICGRAPH_ROUTER_TEMPERATURE`, default `null`) instead. Sent on every path (text, structured, tools, MCP, streamed), shown in `explain()` with its source and seen by `Llm::fake()->assertSettings()`. The cache key covers it. Not checked against the model: some reasoning models accept a temperature only with reasoning off, otherwise the provider rejects the call and it appears as a failed LLM call. When the call also sets a reasoning level, the `ReasoningException` names both settings.
- **Per-call timeout and structured retries.** `->timeout($seconds, $connectSeconds)` sets the provider HTTP timeouts for one request, over `agenticgraph.api.timeout` and `agenticgraph.api.connect_timeout`; each tool step and structured retry is its own request, and a streamed request must finish within it. `->structuredRetries($n)` sets the retries for one structured request, over `agenticgraph.prism.structured.retries`. Both show in `explain()` with source `call`.
- **Reasoning.** `->reasoning($level, $budget)`, or `$reasoning` and `$reasoningBudget` on a node, mapped to each provider's option (OpenAI, Gemini, Anthropic, OpenRouter, Ollama). A setting the provider cannot apply throws `ReasoningException` before the call. Thought tokens are reported where the provider gives them.
- **Prompt files.** Markdown with front matter in `resources/prompts` (`AGENTICGRAPH_PROMPTS_PATH`), loaded with `Prompts::get('support.reply')`. `php artisan agenticgraph:prompts` lists them and exits 1 when one is invalid, for CI. Each call records the file's name, version and hash.
- **Testing.** `Llm::fake($responses)` runs the real request path over Prism's fake and records every request, with `assertPrompted()`, `assertSettings()`, `assertSentCount()` and `assertNothingSent()`.
- **Tracing** (off by default, `AGENTICGRAPH_TRACING=true`). Spans for graphs, nodes, LLM calls, tools, routes, HTTP, database queries, vector operations and your own `Trace::span()` blocks, with tokens and cost (`config/model_pricing.php`, `agenticgraph:pricing:sync`), across queues, interrupts and resumes. Exporters: `auto`, `pulse` (AgenticPulse), `otlp`, `log`, `file` and `null`. By default a trace carries state key names and value types, not values (`AGENTICGRAPH_CAPTURE_STATE`); prompts, responses and state values are sent only when you turn them on.
- **Interrupt expiry on traces.** An interrupt span carries `interrupt.expires_at` and the interrupting segment `trace.interrupt_expires_at` (ISO 8601, the stored interrupt's `expires_at`); both are absent when the interrupt has no expiry or is not persisted. Additive within trace wire v1.1. `NodeInterrupted` gains `expiresAt`, and `TracerExporter::interruptTrace()` an optional `$expiresAt`.
- **Where a node failed: `error.stage`.** A failed node span's `error` carries `stage`: `middleware_before` (a middleware threw before `$next`, so the node never ran), `node` (the node body, `onBefore` or `onAfter` threw; one per attempt) or `middleware_after` (a middleware threw after `$next` returned). Kept with `capture.errors=class`; absent on every other span and on the trace `error`. The OTLP driver sends it as `agenticgraph.error.stage`. Additive within trace wire v1.1. `NodeExecutionFailed` gains `stage`.
- **Trace annotations.** Declare what a run means so a trace can be explained without guessing: a node label and description (`#[Annotate('Look up the order', description: 'Looked up order {order_lookup.order_id}')]` on the class, or `label:`, `description:` on `addNode()`, which win per field), a graph `label()`, an `outcome()` (a headline and up to 8 state paths), `humanInput:` keys read from the state on a resume, and `expectations()` / `toolExpectations()` (`read_only`, `max_retries`, `max_loops`, `max_cost_usd`). Placeholders are `{dot.path}` into the state, `{{` and `}}` literal braces; lengths, counts, syntax and expectation names are checked when the graph is built (`InvalidGraphException`). Rendering never fails a run (a missing key renders empty and is listed in `missing`; an exception becomes `annotation_error` with the class name) and never calls a model. Expectations are declarations for AgenticPulse to check; they never stop or change a run. Declared values are sent in every `capture.state` mode, keys included; `tracing.capture.annotations` (`AGENTICGRAPH_CAPTURE_ANNOTATIONS`: `all`, `static`, `none`) lets the app owner limit them, also for OTLP. Wire v1.1, additive: `trace.annotations`, `annotations` on node, tool and resume spans, `human_input` on resume spans; a graph without declarations sends the same payload as before. `NodeResumed` gains an optional `graphName`, and `ExecuteNodeJob` binds its graph while the node runs.
- **Router settings.** `PrismRouter` takes a `prompt:` template or prompt file, sends `agenticgraph.router.max_tokens` (`AGENTICGRAPH_ROUTER_MAX_TOKENS`, default 1024) and `agenticgraph.router.reasoning` (`AGENTICGRAPH_ROUTER_REASONING`), and logs a warning and dispatches `RouteFellBack` whenever it takes the default route.
- **Events.** `GraphExecutionFailed`, `NodeExecutionFailed`, `NodeInterrupted`, `NodeResumed`, `InterruptPending`, `InterruptExpired`, `CheckpointCreated`, `RouteDecided`, `RouteFellBack`, `LlmCallFailed`, `ReasoningNotDisabled` and `ToolCallCaptured`. `TokensUsed` gains cache, thought-token, streamed and prompt-file fields.
- **Execution settings.** Loops with iteration and cycle limits (`execution.loop`, opt-in), a checkpoint every N nodes (`AGENTICGRAPH_CHECKPOINT_FREQUENCY`), a maximum state size (`AGENTICGRAPH_MAX_STATE_SIZE`), queue job tries and timeouts (`AGENTICGRAPH_EXECUTION_TRIES`, `AGENTICGRAPH_EXECUTION_TIMEOUT`, `AGENTICGRAPH_RESUME_TRIES`, `AGENTICGRAPH_RESUME_TIMEOUT`), `$maxTokens` and `$providerOptions` on nodes (`AGENTICGRAPH_PRISM_MAX_TOKENS`), and image, audio and embedding helpers on `PrismNode`.
- **Pulse 429 handling.** The Pulse exporter honors `Retry-After` (seconds or an HTTP date). A wait up to `tracing.transport.retry_after_wait` (2 s) happens in process and the segment is posted once more; a longer one goes to a delayed `SendPulseSegmentJob` when the app has a real queue (not `sync`), else the exporter waits the cap, posts once more and logs. A 429 without `Retry-After`, a 413 and other 4xx stay logged losses. New `tracing.transport` keys: `retry_after_wait`, `max_delay`, `queue_connection`, `queue_name`, `job_tries`. SQS refuses messages over 256 KB, so a large segment falls back to wait-and-retry-once there.
- **Pulse requests identify the client.** Every exporter request sends `User-Agent: agenticgraph/{version}` (the installed package version) and `Accept: application/json`.

### Changed

- `PrismRouter`'s routing call allows 1024 output tokens by default (it was a fixed 100), so reasoning models no longer fall back to the default route for lack of room.
- Package logs no longer carry state values, model output or stack traces.
- Safe under Laravel Octane and long-running queue workers: no graph, trace, execution or pricing state crosses requests (verified on RoadRunner).
- Config keys that 0.1.0 never read are removed: `checkpoint.strategy` (replaced by `checkpoint.frequency`), `checkpoint.compression`, `checkpoint.encryption`, `events.enabled`, `lock.driver`, `lock.prefix`, `lock.strategy`, `lock.retry_interval`, `logging.channel`, `logging.level`, `logging.log_state`, `execution.max_retries`, `execution.retry_delay`, `execution.parallel`, `prism.timeout`, `prism.track_tokens` and `visualization`. `execution.timeout` now reads `AGENTICGRAPH_EXECUTION_TIMEOUT`.
- **Truncation marker.** A cut value now ends with ` [...truncated N]`, `N` being the original length in bytes (it was ` [...truncated]`). One helper, `CaptureDepth::cut()`, writes it for state values, prompts, responses and event text, and the marker counts toward `capture.max_length`. A receiver that matches the old literal must match the new form.
- **Payload size is measured, not estimated.** The 1 MiB and 10 MiB steps now use the real encoded bytes of the request body (`TracePayloadBuilder::encode()`), so non-ASCII or slash-heavy segments that the old estimate undercounted are shrunk when they should be. `_meta.original_size_bytes` is that encoded size.
- **Execution ids come from one generator** (`ExecutionId::generate()`: `exec_{microtime}_{16 hex}`), also for a `TraceContext` rebuilt without an id (it used `uniqid`).

### Fixed

- A trace envelope of exactly 1 MiB is sent as it is; only one above 1 MiB is shrunk (it was shrunk at 1 MiB). The 10 MiB step already used the same boundary.
- `capture.max_length` below 50 bytes (the longest truncation marker plus a margin, `CaptureDepth::MIN_MAX_LENGTH`) throws an `InvalidArgumentException` naming the key, the value and the minimum when the setting is read, instead of cutting values to a bare marker mid-run.
- A middleware that catches a node's final exception and throws a different one no longer sends a second failed span with the last attempt's id (which replaced that attempt's error with the middleware's). The node's span keeps its own exception and `stage: node`; the middleware's exception is what the run fails with, on the graph span and the trace.
- A false "no outgoing edges" warning for `addJoin()` sources.
- A queued graph job that fails (for example a graph not registered on the worker) marks the execution failed instead of leaving it running.
- `agenticgraph:interrupt:expire` continues the interrupted trace: the failed graph span reuses the stored root span id (one root span in the merged trace), the final segment carries `graph_name` and ends at or after it starts.
- `agenticgraph:interrupt:expire` keeps going when broadcasting fails (for example Reverb is down): the failed `stream_close` broadcast is logged as a warning, here and when a queued job fails. Each interrupt is expired in its own transaction, so one that fails (a throwing `InterruptExpired` listener, for example) is logged and stays pending for the next run while the others expire, and its final trace segment is sent only after the commit, so a rolled-back expiry sends none. `BufferedTraceExporter::sendAfterCommit()` opts a trace into that.
- A value cut at `capture.max_length` no longer splits a multibyte character. The cut left invalid UTF-8, `json_encode` threw, and the whole Pulse segment was lost.
- A value with an invalid UTF-8 byte no longer loses the whole Pulse segment: the encoder replaces the bad sequence with U+FFFD.
- A caller-supplied execution id (it is the trace id) longer than 200 characters throws `InvalidExecutionIdException` instead of being accepted.
- A segment refused with a 429 is no longer lost when Pulse says when to come back (see Pulse 429 handling).

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
